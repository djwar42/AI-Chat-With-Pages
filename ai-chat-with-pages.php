<?php
/**
 * AI Chat With Pages
 *
 * @package       AICHWP
 * @author        Daniel W
 * @version       1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:   AI Chat With Pages
 * Plugin URI:    wordpressaichatwithpages.com
 * Description:   Creates a chatbot that can index your sites content and allow the user to chat with it.
 * Version:       1.0.0
 * Author:        Daniel W
 * Author URI:    wordpressaichatwithpages.com
 * Text Domain:   ai-chat-with-pages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin name
define( 'AICHWP_NAME',			'AI Chat With Pages' );

// Plugin version
define( 'AICHWP_VERSION',		'1.0.0' );

// Plugin Root File
define( 'AICHWP_PLUGIN_FILE',	__FILE__ );

// Plugin base
define( 'AICHWP_PLUGIN_BASE',	plugin_basename( AICHWP_PLUGIN_FILE ) );

// Plugin Folder Path
define( 'AICHWP_PLUGIN_DIR',	plugin_dir_path( AICHWP_PLUGIN_FILE ) );

// Plugin Folder URL
define( 'AICHWP_PLUGIN_URL',	plugin_dir_url( AICHWP_PLUGIN_FILE ) );

/**
 * Load the core functionality
 */
require_once AICHWP_PLUGIN_DIR . 'core/ai-chat-with-pages-settings.php';
require_once AICHWP_PLUGIN_DIR . 'core/ai-chat-with-pages-indexing.php';
require_once AICHWP_PLUGIN_DIR . 'core/ai-chat-with-pages-chat.php';


// Schedule the creation of initial tables when the plugin is activated
register_activation_hook(AICHWP_PLUGIN_FILE, 'aichwp_create_initial_tables');

// // Clear scheduled actions and progress option when the plugin is deactivated
register_deactivation_hook(AICHWP_PLUGIN_FILE, 'aichwp_clear_embeddings_progress');
