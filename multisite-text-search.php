<?php
/**
 * WordPress Multisite Text Search Tool
 * Fast text/shortcode finder across all subsites in a WordPress multisite
 */

class MultisiteTextSearch {
  private $pdo;
  private $table_prefix;
  private $sites;
  private $exclude_patterns = [
      'jpsq_sync*',
      'jetpack_*',
      '*_transient*',
      'wpins_*',
      'active_plugins',
      'recently_activated',
      'fs_accounts',
  ];

  // Search options
  private $search_term;
  private $posts_only = false;
  private $meta_only = false;
  private $options_only = false;
  private $summary_only = false;
  private $case_sensitive = false;
  private $published_only = false;
  private $exclude_revisions = false;

  public function __construct() {
    $this->parseArguments();
    $this->setupDatabase();
    $this->loadSites();
  }

  private function parseArguments() {
    global $argv;

    $this->search_term = null;
    $options = [];

    // Process all arguments after script name
    for ($i = 1; $i < count($argv); $i++) {
      $arg = $argv[$i];

      if (strpos($arg, '--exclude=') === 0) {
        $this->exclude_patterns[] = substr($arg, 10);
      } elseif (strpos($arg, '--') === 0) {
        $options[] = $arg;
      } elseif (!$this->search_term) {
        $this->search_term = $arg;
      }
    }

    // Parse boolean options
    $this->posts_only = in_array('--posts-only', $options);
    $this->meta_only = in_array('--meta-only', $options);
    $this->options_only = in_array('--options-only', $options);
    $this->summary_only = in_array('--summary', $options);
    $this->case_sensitive = in_array('--case-sensitive', $options);
    $this->published_only = in_array('--published-only', $options);
    $this->exclude_revisions = in_array('--exclude-revisions', $options);

    // Show help
    if (!$this->search_term || in_array('--help', $options) || in_array('-h', $options)) {
      $this->showHelp();
      exit(0);
    }
  }

  private function setupDatabase() {
    $db_config = $this->detectEnvironment();
    $this->table_prefix = $this->detectTablePrefix();

    echo "Environment detected: {$db_config['type']}\n";
    if ($db_config['type'] !== 'default') {
      echo "Database: {$db_config['name']} on {$db_config['host']}\n";
    }
    echo "Table prefix: {$this->table_prefix}\n";
    echo str_repeat("-", 40) . "\n";

    try {
      $dsn = "mysql:host={$db_config['host']};dbname={$db_config['name']};charset=utf8mb4";
      $this->pdo = new PDO($dsn, $db_config['user'], $db_config['pass']);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
      echo "Database Error: " . $e->getMessage() . "\n";
      exit(1);
    }
  }

  private function loadSites() {
    $sites_query = "SELECT blog_id, domain, path FROM {$this->table_prefix}blogs WHERE deleted = 0 AND spam = 0 ORDER BY blog_id";
    $sites_stmt = $this->pdo->prepare($sites_query);
    $sites_stmt->execute();
    $this->sites = $sites_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add protocol detection for each site
    foreach ($this->sites as &$site) {
      $site['protocol'] = $this->detectSiteProtocol($site['blog_id']);
    }
  }

  private function detectSiteProtocol($blog_id) {
    $table_suffix = ($blog_id == 1) ? '' : $blog_id . '_';
    $options_table = "{$this->table_prefix}{$table_suffix}options";

    if ($this->tableExists($options_table)) {
      $protocol_query = "SELECT option_value FROM $options_table WHERE option_name = 'home' LIMIT 1";
      $stmt = $this->pdo->prepare($protocol_query);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($result && $result['option_value']) {
        $parsed = parse_url($result['option_value']);
        return $parsed['scheme'] ?? 'http';
      }
    }
    return 'http'; // fallback
  }

  public function run() {
    $search_pattern = $this->case_sensitive ? "%{$this->search_term}%" : "%{$this->search_term}%";
    $collation = $this->case_sensitive ? "COLLATE utf8mb4_bin" : "";

    echo "Searching for: \"{$this->search_term}\"\n";
    echo "Options: " . implode(', ', array_filter([
            $this->posts_only ? 'posts-only' : null,
            $this->meta_only ? 'meta-only' : null,
            $this->options_only ? 'options-only' : null,
            $this->summary_only ? 'summary' : null,
            $this->case_sensitive ? 'case-sensitive' : 'case-insensitive',
            $this->published_only ? 'published-only' : null,
            $this->exclude_revisions ? 'exclude-revisions' : null,
            !empty($this->exclude_patterns) ? 'excluding: ' . implode(', ', $this->exclude_patterns) : null
        ])) . "\n";
    echo "=" . str_repeat("=", 60) . "\n";

    $total_found = 0;
    $sites_with_matches = 0;

    echo "Scanning " . count($this->sites) . " active sites...\n\n";

    foreach ($this->sites as $site) {
      $results = $this->searchSite($site, $search_pattern, $collation);

      if (!empty($results)) {
        $sites_with_matches++;
        $total_found += count($results);
        $this->outputSiteResults($site, $results);
      }

      static $processed = 0;
      $processed++;
      if ($processed % 100 == 0 && !$this->summary_only) {
        echo "Processed $processed sites...\n";
      }
    }

    $this->outputSearchSummary($total_found, $sites_with_matches);
  }

  private function searchSite($site, $search_pattern, $collation) {
    $blog_id = $site['blog_id'];
    $domain = $site['domain'];
    $path = $site['path'];
    $protocol = $site['protocol'];
    $site_url = "{$protocol}://{$domain}{$path}";
    $table_suffix = ($blog_id == 1) ? '' : $blog_id . '_';

    $results = [];

    // Search posts
    if (!$this->meta_only && !$this->options_only) {
      $results = array_merge($results, $this->searchPosts($table_suffix, $search_pattern, $collation, $site_url, $domain, $path));
    }

    // Search postmeta
    if (!$this->posts_only && !$this->options_only) {
      $results = array_merge($results, $this->searchPostMeta($table_suffix, $search_pattern, $collation, $site_url, $domain, $path));
    }

    // Search options
    if (!$this->posts_only && !$this->meta_only) {
      $results = array_merge($results, $this->searchOptions($table_suffix, $search_pattern, $collation, $site_url, $domain, $path));
    }

    return $results;
  }

  private function searchPosts($table_suffix, $search_pattern, $collation, $site_url, $domain, $path) {
    $posts_table = "{$this->table_prefix}{$table_suffix}posts";
    if (!$this->tableExists($posts_table)) {
      return [];
    }

    if ($this->published_only) {
      $status_filter = "AND post_status = 'publish'";
    } elseif ($this->exclude_revisions) {
      $status_filter = "AND post_status IN ('publish', 'private', 'draft')";
    } else {
      $status_filter = "AND post_status IN ('publish', 'private', 'draft', 'inherit')";
    }

    $posts_query = "SELECT ID, post_title, post_type, post_status, post_name, post_parent FROM $posts_table WHERE post_content LIKE ? $collation $status_filter";
    $stmt = $this->pdo->prepare($posts_query);
    $stmt->execute([$search_pattern]);
    $post_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($post_results as $post) {
      $location = "Post: \"{$post['post_title']}\" (ID: {$post['ID']}, Type: {$post['post_type']}, Status: {$post['post_status']})";

      if ($post['post_type'] === 'revision') {
        $edit_url = $site_url . "wp-admin/revision.php?revision={$post['ID']}";
        if ($post['post_parent'] > 0) {
          $location .= " → Parent ID: {$post['post_parent']}";
        }
      } else {
        $edit_url = $site_url . "wp-admin/post.php?post={$post['ID']}&action=edit";
      }

      $results[] = [
          'type' => 'post',
          'location' => $location,
          'url' => $edit_url
      ];
    }

    return $results;
  }

  private function searchPostMeta($table_suffix, $search_pattern, $collation, $site_url, $domain, $path) {
    $postmeta_table = "{$this->table_prefix}{$table_suffix}postmeta";
    $posts_table = "{$this->table_prefix}{$table_suffix}posts";

    if (!$this->tableExists($postmeta_table)) {
      return [];
    }

    $postmeta_query = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status FROM $postmeta_table pm JOIN $posts_table p ON pm.post_id = p.ID WHERE pm.meta_value LIKE ? $collation";
    $stmt = $this->pdo->prepare($postmeta_query);
    $stmt->execute([$search_pattern]);
    $meta_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($meta_results as $meta) {
      if ($this->shouldExclude($meta['meta_key'])) {
        continue;
      }

      $results[] = [
          'type' => 'postmeta',
          'location' => "Meta: \"{$meta['post_title']}\" (ID: {$meta['post_id']}, Key: {$meta['meta_key']})",
          'url' => $site_url . "wp-admin/post.php?post={$meta['post_id']}&action=edit",
          'wp_cli' => "ddev wp --url={$domain}{$path} post meta get {$meta['post_id']} {$meta['meta_key']}"
      ];
    }

    return $results;
  }

  private function searchOptions($table_suffix, $search_pattern, $collation, $site_url, $domain, $path) {
    $options_table = "{$this->table_prefix}{$table_suffix}options";
    if (!$this->tableExists($options_table)) {
      return [];
    }

    $options_query = "SELECT option_name, option_id, option_value FROM $options_table WHERE (option_value LIKE ? OR option_name LIKE ?) $collation";
    $stmt = $this->pdo->prepare($options_query);
    $stmt->execute([$search_pattern, $search_pattern]);
    $option_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($option_results as $option) {
      if ($this->shouldExclude($option['option_name'])) {
        continue;
      }

      $results[] = [
          'type' => 'option',
          'location' => "Option: {$option['option_name']} (ID: {$option['option_id']})",
          'url' => $site_url . "wp-admin/options-general.php",
          'wp_cli' => "ddev wp --url={$domain}{$path} option get {$option['option_name']}"
      ];
    }

    return $results;
  }

  private function tableExists($table_name) {
    $check_table = $this->pdo->prepare("SHOW TABLES LIKE ?");
    $check_table->execute([$table_name]);
    return $check_table->rowCount() > 0;
  }

  private function shouldExclude($name) {
    if (empty($this->exclude_patterns)) {
      return false;
    }
    foreach ($this->exclude_patterns as $pattern) {
      $escaped = preg_quote($pattern, '/');
      $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], $escaped) . '$/i';
      if (preg_match($regex, $name)) {
        return true;
      }
    }
    return false;
  }

  private function outputSiteResults($site, $results) {
    $blog_id = $site['blog_id'];
    $domain = $site['domain'];
    $path = $site['path'];
    $protocol = $site['protocol'];
    $site_url = "{$protocol}://{$domain}{$path}";

    if ($this->summary_only) {
      echo "✓ {$domain}{$path} (Blog ID: {$blog_id}) - " . count($results) . " matches\n";
    } else {
      echo "FOUND in: {$domain}{$path} (Blog ID: {$blog_id}) - " . count($results) . " matches\n";
      echo "URL: {$site_url}\n";

      foreach ($results as $result) {
        echo "  └─ {$result['location']}\n";

        if (isset($result['wp_cli']) && !$this->summary_only) {
          echo "     WP-CLI: {$result['wp_cli']}\n";
        }

        if (!$this->summary_only && $result['type'] === 'post') {
          echo "     → {$result['url']}\n";
        }
      }
      echo "\n";
    }
  }

  private function outputSearchSummary($total_found, $sites_with_matches) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "SEARCH SUMMARY:\n";
    echo "Search term: \"{$this->search_term}\"\n";
    echo "Total matches found: $total_found\n";
    echo "Sites with matches: $sites_with_matches\n";
    echo "Total sites scanned: " . count($this->sites) . "\n";

    if ($total_found == 0) {
      echo "\nNo matches found for \"{$this->search_term}\".\n";
    } else {
      $percentage = round(($sites_with_matches / count($this->sites)) * 100, 1);
      echo "Match rate: {$percentage}% of sites contain this text\n";
    }
  }

  private function detectEnvironment() {
    // Check for DDEV
    if (getenv('IS_DDEV_PROJECT') === 'true' || getenv('DDEV_HOSTNAME')) {
      return [
          'type' => 'ddev',
          'host' => 'db',
          'name' => 'db',
          'user' => 'db',
          'pass' => 'db'
      ];
    }

    // Check for Pantheon
    if (getenv('PANTHEON_ENVIRONMENT') || getenv('PRESSFLOW_SETTINGS')) {
      $pressflow = getenv('PRESSFLOW_SETTINGS');
      if ($pressflow) {
        $settings = json_decode($pressflow, true);
        if ($settings && isset($settings['databases']['default']['default'])) {
          $db = $settings['databases']['default']['default'];
          return [
              'type' => 'pantheon',
              'host' => $db['host'] . ':' . $db['port'],
              'name' => $db['database'],
              'user' => $db['username'],
              'pass' => $db['password']
          ];
        }
      }
    }

    // Check for WP Engine
    if (getenv('WPE_APIKEY') || strpos(gethostname(), 'wpe') !== false) {
      return [
          'type' => 'wpengine',
          'host' => getenv('DB_HOST') ?: 'localhost',
          'name' => getenv('DB_NAME') ?: 'wordpress',
          'user' => getenv('DB_USER') ?: 'wordpress',
          'pass' => getenv('DB_PASSWORD') ?: ''
      ];
    }

    // Check for standard WordPress environment variables
    if (getenv('DB_HOST') || getenv('DATABASE_URL')) {
      if (getenv('DATABASE_URL')) {
        $url = parse_url(getenv('DATABASE_URL'));
        return [
            'type' => 'env_url',
            'host' => $url['host'] . (isset($url['port']) ? ':' . $url['port'] : ''),
            'name' => ltrim($url['path'], '/'),
            'user' => $url['user'],
            'pass' => $url['pass']
        ];
      } else {
        return [
            'type' => 'env_vars',
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME') ?: 'wordpress',
            'user' => getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root',
            'pass' => getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: ''
        ];
      }
    }

    // Check for wp-config.php
    $current_dir = getcwd();
    for ($i = 0; $i < 5; $i++) {
      $wp_config = $current_dir . '/wp-config.php';
      if (file_exists($wp_config)) {
        $config = file_get_contents($wp_config);
        preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $host_match);
        preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $name_match);
        preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $user_match);
        preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $pass_match);

        if ($host_match && $name_match && $user_match) {
          return [
              'type' => 'wp-config',
              'host' => $host_match[1],
              'name' => $name_match[1],
              'user' => $user_match[1],
              'pass' => $pass_match[1] ?? ''
          ];
        }
      }
      $current_dir = dirname($current_dir);
      if ($current_dir === '/') break;
    }

    return [
        'type' => 'default',
        'host' => 'localhost',
        'name' => 'wordpress',
        'user' => 'root',
        'pass' => ''
    ];
  }

  private function detectTablePrefix() {
    $current_dir = getcwd();
    for ($i = 0; $i < 5; $i++) {
      $wp_config = $current_dir . '/wp-config.php';
      if (file_exists($wp_config)) {
        $config = file_get_contents($wp_config);
        preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $config, $prefix_match);
        if ($prefix_match) {
          return $prefix_match[1];
        }
      }
      $current_dir = dirname($current_dir);
      if ($current_dir === '/') break;
    }
    return 'wp_';
  }

  private function showHelp() {
    echo "WordPress Multisite Text Search Tool\n";
    echo "====================================\n\n";
    echo "Usage: php " . basename(__FILE__) . " \"search_string\" [options]\n\n";
    echo "Options:\n";
    echo "  --posts-only     Search only in post content (faster)\n";
    echo "  --meta-only      Search only in post meta\n";
    echo "  --options-only   Search only in options table\n";
    echo "  --summary        Show only summary counts per site\n";
    echo "  --case-sensitive Case-sensitive search (default: case-insensitive)\n";
    echo "  --published-only Search only published content\n";
    echo "  --exclude-revisions Exclude post revisions from search\n";
    echo "  --exclude=PATTERN Exclude options/meta matching pattern (supports wildcards)\n";
    echo "  --help, -h       Show this help message\n\n";
    echo "Examples:\n";
    echo "  php " . basename(__FILE__) . " \"[alpine-phototile-for-flickr\"\n";
    echo "  php " . basename(__FILE__) . " \"contact-form-7\" --posts-only\n";
    echo "  php " . basename(__FILE__) . " \"old-domain.com\" --summary\n";
    echo "  php " . basename(__FILE__) . " \"text\" --exclude=\"jpsq_sync*\" --exclude=\"*cache*\"\n";
    echo "  php " . basename(__FILE__) . " \"Facebook\" --case-sensitive\n";
  }
}

// Run the application
try {
  $start_time = microtime(true);
  $search = new MultisiteTextSearch();
  $search->run();
  $execution_time = number_format(microtime(true) - $start_time, 2);
  echo "\nCompleted in $execution_time seconds.\n";
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
  exit(1);
}
