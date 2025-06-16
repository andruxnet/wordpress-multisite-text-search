<?php
/**
 * WordPress Multisite Text Search Tool
 * Fast text/shortcode finder across all subsites in a WordPress multisite
 *
 * Usage: php multisite-text-search.php "search_string" [options]
 * Examples:
 *   php multisite-text-search.php "[alpine-phototile-for-flickr"
 *   php multisite-text-search.php "contact-form-7" --posts-only
 *   php multisite-text-search.php "old-domain.com" --summary
 */

// Parse command line arguments
$search_term = $argv[1] ?? null;
$options = array_slice($argv, 2);

// Parse exclude patterns
$exclude_patterns = [];
foreach ($options as $key => $option) {
  if (strpos($option, '--exclude=') === 0) {
    $exclude_patterns[] = substr($option, 10);
    unset($options[$key]);
  }
}

// Auto-detect environment and set database connection
function detectEnvironment() {
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

  // Check for Pantheon (Terminus)
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

// Detect table prefix from wp-config.php
function detectTablePrefix() {
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

// Function to check if a name matches any exclude pattern
function shouldExclude($name, $patterns) {
  if (empty($patterns)) {
    return false;
  }
  foreach ($patterns as $pattern) {
    $escaped = preg_quote($pattern, '/');
    $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], $escaped) . '$/i';
    if (preg_match($regex, $name)) {
      return true;
    }
  }
  return false;
}

// Help message
if (!$search_term || in_array('--help', $options) || in_array('-h', $options)) {
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
  echo "  --exclude=PATTERN Exclude options/meta matching pattern (supports wildcards)\n";
  echo "  --include-revisions Include post revisions in search\n";
  echo "  --help, -h       Show this help message\n\n";
  echo "Examples:\n";
  echo "  php " . basename(__FILE__) . " \"[alpine-phototile-for-flickr\"\n";
  echo "  php " . basename(__FILE__) . " \"contact-form-7\" --posts-only\n";
  echo "  php " . basename(__FILE__) . " \"old-domain.com\" --summary\n";
  echo "  php " . basename(__FILE__) . " \"text\" --exclude=\"jpsq_sync*\" --exclude=\"*cache*\"\n";
  echo "  php " . basename(__FILE__) . " \"Facebook\" --case-sensitive\n";
  exit(0);
}

// Parse options
$posts_only = in_array('--posts-only', $options);
$meta_only = in_array('--meta-only', $options);
$options_only = in_array('--options-only', $options);
$summary_only = in_array('--summary', $options);
$case_sensitive = in_array('--case-sensitive', $options);
$published_only = in_array('--published-only', $options);
$include_revisions = in_array('--include-revisions', $options);

// Add common exclusions
$exclude_patterns = array_merge($exclude_patterns, [
    'jpsq_sync*',
    'jetpack_*',
    '*_transient*',
    'wpins_*',
    'active_plugins',
    'recently_activated',
    'fs_accounts',
]);

$db_config = detectEnvironment();
$table_prefix = detectTablePrefix();

echo "Environment detected: {$db_config['type']}\n";
if ($db_config['type'] !== 'default') {
  echo "Database: {$db_config['name']} on {$db_config['host']}\n";
}
echo "Table prefix: {$table_prefix}\n";
echo str_repeat("-", 40) . "\n";

try {
  $dsn = "mysql:host={$db_config['host']};dbname={$db_config['name']};charset=utf8mb4";
  $pdo = new PDO($dsn, $db_config['user'], $db_config['pass']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $search_pattern = $case_sensitive ? "%{$search_term}%" : "%{$search_term}%";
  $collation = $case_sensitive ? "COLLATE utf8mb4_bin" : "";

  echo "Searching for: \"$search_term\"\n";
  echo "Options: " . implode(', ', array_filter([
          $posts_only ? 'posts-only' : null,
          $meta_only ? 'meta-only' : null,
          $options_only ? 'options-only' : null,
          $summary_only ? 'summary' : null,
          $case_sensitive ? 'case-sensitive' : 'case-insensitive',
          $published_only ? 'published-only' : null,
          !empty($exclude_patterns) ? 'excluding: ' . implode(', ', $exclude_patterns) : null
      ])) . "\n";
  echo "=" . str_repeat("=", 60) . "\n";

  $total_found = 0;
  $sites_with_matches = 0;

  $sites_query = "SELECT blog_id, domain, path FROM {$table_prefix}blogs WHERE deleted = 0 AND spam = 0 ORDER BY blog_id";
  $sites_stmt = $pdo->prepare($sites_query);
  $sites_stmt->execute();
  $sites = $sites_stmt->fetchAll(PDO::FETCH_ASSOC);

  echo "Scanning " . count($sites) . " active sites...\n\n";

  foreach ($sites as $site) {
    $blog_id = $site['blog_id'];
    $domain = $site['domain'];
    $path = $site['path'];
    $site_url = "http://{$domain}{$path}";
    $table_suffix = ($blog_id == 1) ? '' : $blog_id . '_';

    $site_results = [];
    $site_total = 0;
    $check_table = $pdo->prepare("SHOW TABLES LIKE ?");

    // Search posts
    if (!$meta_only && !$options_only) {
      $posts_table = "{$table_prefix}{$table_suffix}posts";
      $check_table->execute([$posts_table]);

      if ($check_table->rowCount() > 0) {
        $status_filter = $published_only ? "AND post_status = 'publish'" : "AND post_status IN ('publish', 'private', 'draft', 'inherit')";
        $posts_query = "SELECT ID, post_title, post_type, post_status, post_name FROM $posts_table WHERE post_content LIKE ? $collation $status_filter";

        $stmt = $pdo->prepare($posts_query);
        $stmt->execute([$search_pattern]);
        $post_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($post_results as $post) {
          $site_results[] = [
              'type' => 'post',
              'location' => "Post: \"{$post['post_title']}\" (ID: {$post['ID']}, Type: {$post['post_type']}, Status: {$post['post_status']})",
              'url' => $site_url . "?p={$post['ID']}"
          ];
          $site_total++;
        }
      }
    }

    // Search postmeta
    if (!$posts_only && !$options_only) {
      $postmeta_table = "{$table_prefix}{$table_suffix}postmeta";
      $posts_table = "{$table_prefix}{$table_suffix}posts";
      $check_table->execute([$postmeta_table]);

      if ($check_table->rowCount() > 0) {
        $postmeta_query = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status FROM $postmeta_table pm JOIN $posts_table p ON pm.post_id = p.ID WHERE pm.meta_value LIKE ? $collation";

        $stmt = $pdo->prepare($postmeta_query);
        $stmt->execute([$search_pattern]);
        $meta_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($meta_results as $meta) {
          if (shouldExclude($meta['meta_key'], $exclude_patterns)) {
            continue;
          }

          $site_results[] = [
              'type' => 'postmeta',
              'location' => "Meta: \"{$meta['post_title']}\" (ID: {$meta['post_id']}, Key: {$meta['meta_key']})",
              'url' => $site_url . "?p={$meta['post_id']}",
              'wp_cli' => "ddev wp --url={$domain}{$path} post meta get {$meta['post_id']} {$meta['meta_key']}"
          ];
          $site_total++;
        }
      }
    }

    // Search options
    if (!$posts_only && !$meta_only) {
      $options_table = "{$table_prefix}{$table_suffix}options";
      $check_table->execute([$options_table]);

      if ($check_table->rowCount() > 0) {
        $options_query = "SELECT option_name, option_id, option_value FROM $options_table WHERE option_value LIKE ? $collation";

        $stmt = $pdo->prepare($options_query);
        $stmt->execute([$search_pattern]);
        $option_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($option_results as $option) {
          if (shouldExclude($option['option_name'], $exclude_patterns)) {
            continue;
          }

          $site_results[] = [
              'type' => 'option',
              'location' => "Option: {$option['option_name']} (ID: {$option['option_id']})",
              'url' => $site_url . "wp-admin/options-general.php",
              'wp_cli' => "ddev wp --url={$domain}{$path} option get {$option['option_name']}"
          ];
          $site_total++;
        }
      }
    }

    // Output results
    if ($site_total > 0) {
      $sites_with_matches++;
      $total_found += $site_total;

      if ($summary_only) {
        echo "✓ {$domain}{$path} (Blog ID: {$blog_id}) - {$site_total} matches\n";
      } else {
        echo "FOUND in: {$domain}{$path} (Blog ID: {$blog_id}) - {$site_total} matches\n";
        echo "URL: {$site_url}\n";

        foreach ($site_results as $result) {
          echo "  └─ {$result['location']}\n";

          if (isset($result['wp_cli']) && !$summary_only) {
            echo "     WP-CLI: {$result['wp_cli']}\n";
          }

          if (!$summary_only && $result['type'] === 'post') {
            echo "     → {$result['url']}\n";
          }
        }
        echo "\n";
      }
    }

    static $processed = 0;
    $processed++;
    if ($processed % 100 == 0 && !$summary_only) {
      echo "Processed $processed sites...\n";
    }
  }

  echo "\n" . str_repeat("=", 60) . "\n";
  echo "SEARCH SUMMARY:\n";
  echo "Search term: \"$search_term\"\n";
  echo "Total matches found: $total_found\n";
  echo "Sites with matches: $sites_with_matches\n";
  echo "Total sites scanned: " . count($sites) . "\n";

  if ($total_found == 0) {
    echo "\nNo matches found for \"$search_term\".\n";
  } else {
    $percentage = round(($sites_with_matches / count($sites)) * 100, 1);
    echo "Match rate: {$percentage}% of sites contain this text\n";
  }

} catch (PDOException $e) {
  echo "Database Error: " . $e->getMessage() . "\n";
  echo "\nCheck your database connection and environment.\n";
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}

echo "\nSearch completed in " . number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . " seconds.\n";
