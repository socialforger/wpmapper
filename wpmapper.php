<?php
/**
 * Plugin Name: wpmapper
 * Description: Estensione nativa avanzata per bpglocation / Leaflet Map. Supporta mappe dati, overlay raster e disegno cooperativo regolato per ID utenti con scadenze temporanee.
 * Version: 1.4.0
 * Author: Socialforger
 * License: GPL2
 * Text Domain: wpmapper
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =======================================================
// CARICAMENTO LINGUA E CONTROLLO DIPENDENZE
// =======================================================
add_action( 'plugins_loaded', 'wpm_load_textdomain_and_init' );

function wpm_load_textdomain_and_init() {
    // Carica i file di traduzione dalla cartella /languages/
    load_plugin_textdomain( 'wpmapper', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    // Avvia la verifica delle dipendenze
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    $leaflet_active    = is_plugin_active( 'leaflet-map/leaflet-map.php' );
    $extensions_active = is_plugin_active( 'extensions-for-leaflet-map/extensions-for-leaflet-map.php' );

    if ( ! $leaflet_active || ! $extensions_active ) {
        add_action( 'admin_notices', 'wpm_render_dependency_error_notice' );
        return; 
    }

    // Inizializza i moduli interni del plugin
    wpm_initialize_plugin_logic();
}

function wpm_render_dependency_error_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>wpmapper:</strong> <?php _e( 'Il plugin è in pausa di sicurezza perché richiede i seguenti moduli attivi:', 'wpmapper' ); ?>
            <br />
            1. <code>Leaflet Map</code> &mdash; 2. <code>Extensions for Leaflet Map</code>
        </p>
    </div>
    <?php
}

function wpm_initialize_plugin_logic() {
    add_action( 'admin_menu', 'wpm_admin_menu' );
    add_filter( 'bpglocation_leaflet_shortcode_string', 'wpm_inject_leaflet_shortcodes' );
    add_action( 'wp_enqueue_scripts', 'wpm_enqueue_drawing_tools' );
    add_action( 'wp_ajax_wpm_save_geometry', 'wpm_save_geometry_callback' );
}

// =======================================================
// PANNELLO DI CONFIGURAZIONE ADMIN
// =======================================================
function wpm_admin_menu() {
    add_submenu_page( 'options-general.php', 'wpmapper', 'wpmapper', 'manage_options', 'wpmapper', 'wpm_render_settings_page' );
}

function wpm_render_settings_page() {
    if ( isset($_POST['wpm_save_settings']) ) {
        check_admin_referer( 'wpm_settings_nonce' );
        update_option( 'wpm_choropleth_url', sanitize_text_field($_POST['wpm_choropleth_url']) );
        update_option( 'wpm_raster_url', sanitize_text_field($_POST['wpm_raster_url']) );
        update_option( 'wpm_raster_bounds', sanitize_text_field($_POST['wpm_raster_bounds']) );
        
        $user_ids_raw = sanitize_text_field($_POST['wpm_allowed_user_ids']);
        $user_ids_clean = preg_replace('/\s+/', '', $user_ids_raw);
        update_option( 'wpm_allowed_user_ids', $user_ids_clean );

        echo '<div class="updated"><p>' . __( 'Impostazioni aggiornate con successo!', 'wpmapper' ) . '</p></div>';
    }
    
    $choropleth_url   = get_option( 'wpm_choropleth_url', '' );
    $raster_url       = get_option( 'wpm_raster_url', '' );
    $raster_bounds    = get_option( 'wpm_raster_bounds', '' );
    $allowed_user_ids = get_option( 'wpm_allowed_user_ids', '' );
    ?>
    <div class="wrap">
        <h1>⚙️ wpmapper &mdash; <?php _e( 'Pannello di Configurazione', 'wpmapper' ); ?></h1>
        <form method="POST" action="">
            <?php wp_nonce_field( 'wpm_settings_nonce' ); ?>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #11a8e5;">
                <h2>👥 <?php _e( 'Autorizzazioni di Scrittura per ID Utenti', 'wpmapper' ); ?></h2>
                <p><?php _e( 'Inserisci gli ID degli utenti BuddyBoss abilitati al disegno oltre agli amministratori, separati da una virgola:', 'wpmapper' ); ?></p>
                <input type="text" name="wpm_allowed_user_ids" value="<?php echo esc_attr($allowed_user_ids); ?>" style="width: 100%; font-family: monospace; padding: 6px;" placeholder="Es: 4, 15, 32">
            </div>

            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2>📊 <?php _e( '1. Mappa Dati (Choropleth GeoJSON URL)', 'wpmapper' ); ?></h2>
                <input type="text" name="wpm_choropleth_url" value="<?php echo esc_url($choropleth_url); ?>" style="width:100%; padding: 6px;" placeholder="https://tuosito.com/roma-reddito.geojson">
            </div>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2>🖼️ <?php _e( '2. Mappa Grafica Raster (Image Overlay / Piantine)', 'wpmapper' ); ?></h2>
                <p><?php _e( 'URL Immagine:', 'wpmapper' ); ?></p>
                <input type="text" name="wpm_raster_url" value="<?php echo esc_url($raster_url); ?>" style="width:100%; padding: 6px;" placeholder="https://tuosito.com/piantina.png"><br><br>
                <p><?php _e( 'Limiti geografici dei bordi (Bounds format: SW_lat, SW_lng, NE_lat, NE_lng):', 'wpmapper' ); ?></p>
                <input type="text" name="wpm_raster_bounds" value="<?php echo esc_attr($raster_bounds); ?>" style="width:100%; font-family:monospace; padding: 6px;" placeholder="41.7,12.3,42.1,12.6">
            </div>
            
            <input type="submit" name="wpm_save_settings" class="button button-primary" value="<?php _e( 'Salva Impostazioni', 'wpmapper' ); ?>">
        </form>
    </div>
    <?php
}

// =======================================================
// INTERCETTAZIONE E INIEZIONE DEI LIVELLI DI EXTENSIONS
// =======================================================
function wpm_inject_leaflet_shortcodes( $shortcode_string ) {
    $choropleth_url = get_option( 'wpm_choropleth_url', '' );
    $raster_url     = get_option( 'wpm_raster_url', '' );
    $raster_bounds  = get_option( 'wpm_raster_bounds', '' );

    $shortcode_string .= '[layers]';

    if ( ! empty( $choropleth_url ) ) {
        $shortcode_string .= sprintf( '[leaflet-geojson src="%s" name="📊 Mappa Dati" color="red" fillOpacity="0.4"]', esc_url( $choropleth_url ) );
    }
    if ( ! empty( $raster_url ) && ! empty( $raster_bounds ) ) {
        $shortcode_string .= sprintf( '[leaflet-image url="%s" bounds="%s" name="🖼️ Piantina Grafica" opacity="0.85"]', esc_url( $raster_url ), esc_attr( $raster_bounds ) );
    }
    return $shortcode_string;
}

// =======================================================
// CONTROLLO PERMESSI DI SCRITTURA E PULIZIA SCADENZE
// =======================================================
function wpm_current_user_can_draw() {
    if ( current_user_can( 'manage_options' ) ) return true;
    if ( ! is_user_logged_in() ) return false;

    $allowed_ids_str = get_option( 'wpm_allowed_user_ids', '' );
    if ( empty( $allowed_ids_str ) ) return false;

    $allowed_ids_array = array_map( 'intval', explode( ',', $allowed_ids_str ) );
    return in_array( get_current_user_id(), $allowed_ids_array, true );
}

function wpm_get_clean_geojson_features() {
    $raw_json = get_option( 'wpm_editable_geojson', '{"type":"FeatureCollection","features":[]}' );
    $geojson  = json_decode( $raw_json, true );
    
    if ( ! isset( $geojson['features'] ) || ! is_array( $geojson['features'] ) ) {
        return array( 'type' => 'FeatureCollection', 'features' => array() );
    }

    $current_time = time();
    $valid_features = array();
    $db_cleanup_needed = false;

    foreach ( $geojson['features'] as $feature ) {
        if ( isset( $feature['properties']['expiry'] ) && ! empty( $feature['properties']['expiry'] ) ) {
            if ( intval( $feature['properties']['expiry'] ) < $current_time ) {
                $db_cleanup_needed = true; 
                continue; // Elemento eliminato automaticamente perché scaduto
            }
        }
        $valid_features[] = $feature;
    }

    $clean_geojson = array( 'type' => 'FeatureCollection', 'features' => $valid_features );

    if ( $db_cleanup_needed ) {
        update_option( 'wpm_editable_geojson', json_encode( $clean_geojson ) );
    }
    return $clean_geojson;
}

// Caricamento selettivo asset di disegno
function wpm_enqueue_drawing_tools() {
    $can_user_draw = wpm_current_user_can_draw();

    if ( $can_user_draw ) {
        wp_enqueue_style( 'leaflet-geoman-css', 'https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.css', array(), '2.14.0' );
        wp_enqueue_script( 'leaflet-geoman-js', 'https://unpkg.com/@geoman-io/leaflet-geoman-free@latest/dist/leaflet-geoman.min.js', array(), '2.14.0', true );
    }

    wp_enqueue_style( 'wpm-style', plugin_dir_url(__FILE__) . 'assets/css/wpm-style.css', array(), '1.4.0' );
    wp_enqueue_script( 'wpm-advanced-layers', plugin_dir_url(__FILE__) . 'assets/js/wpm-advanced-layers.js', array('jquery'), '1.4.0', true );

    wp_localize_script( 'wpm-advanced-layers', 'wpmSettings', array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'wpm_save_nonce' ),
        'drawn_features' => wpm_get_clean_geojson_features(),
        'can_draw'       => $can_user_draw,
        'msg_prompt'     => __( 'Inserisci una descrizione per questo elemento:', 'wpmapper' ),
        'msg_temp'       => __( 'Vuoi che questo elemento sia TEMPORANEO?\n[OK = Temporaneo / Annulla = Permanente]', 'wpmapper' ),
        'msg_hours'      => __( 'Tra quante ORE deve sparire dalla mappa?', 'wpmapper' ),
        'msg_expired_in' => __( '⏳ Scade tra:', 'wpmapper' ),
        'msg_minutes'    => __( 'minuti', 'wpmapper' ),
        'msg_label_temp' => __( '⏳ Elemento Temporaneo', 'wpmapper' )
    ) );
}

// Endpoint AJAX
function wpm_save_geometry_callback() {
    check_ajax_referer( 'wpm_save_nonce', 'nonce' );
    if ( ! wpm_current_user_can_draw() ) {
        wp_send_json_error( __( 'Non autorizzato.', 'wpmapper' ) );
    }

    if ( isset($_POST['geojson']) ) {
        update_option( 'wpm_editable_geojson', $_POST['geojson'] );
        wp_send_json_success();
    }
    wp_send_json_error();
}
