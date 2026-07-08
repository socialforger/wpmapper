<?php
/**
 * Plugin Name: wpmapper
 * Description: Modulo cartografico avanzato per l'Atlante delle Battaglie. Gestisce layer locali, raster, coropletici e URL remoti On-the-Fly a impatto zero sul server, sfruttando i ruoli nativi di WordPress.
 * Version: 2.4.0
 * Author: Socialforger
 * License: GPL2
 * Text Domain: wpmapper
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// HOOK DI ATTIVAZIONE: CREAZIONE AUTOMATICA DELLE PAGINE BASE
register_activation_hook( __FILE__, 'wpm_plugin_activation_logic' );

function wpm_plugin_activation_logic() {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

    // 1. Generazione automatica della pagina Archivio Mappe
    if ( ! get_option( 'wpm_archive_page_id' ) ) {
        $page_check = get_page_by_path( 'maparchive' );
        if ( ! isset( $page_check->ID ) ) {
            $page_id = wp_insert_post( array(
                'post_title'   => 'Archivio Mappe',
                'post_content' => '[wpmapper_archive]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'maparchive'
            ) );
            if ( ! is_wp_error( $page_id ) ) update_option( 'wpm_archive_page_id', $page_id );
        } else {
            update_option( 'wpm_archive_page_id', $page_check->ID );
        }
    }

    // 2. Generazione automatica della mappa principale se non gestita da bpglocation
    if ( ! is_plugin_active( 'bpglocation/bpglocation.php' ) ) {
        $map_page_check = get_page_by_path( 'map' );
        if ( ! isset( $map_page_check->ID ) ) {
            $map_page_id = wp_insert_post( array(
                'post_title'   => 'Map',
                'post_content' => '[leaflet-map height="550"]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'map'
            ) );
            if ( ! is_wp_error( $map_page_id ) ) update_option( 'bpgl_map_page_id', $map_page_id ); 
        } else {
            if ( ! get_option( 'bpgl_map_page_id' ) ) update_option( 'bpgl_map_page_id', $map_page_check->ID );
        }
    }
}

add_action( 'plugins_loaded', 'wpm_load_textdomain_and_init' );

function wpm_load_textdomain_and_init() {
    load_plugin_textdomain( 'wpmapper', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if ( ! is_plugin_active( 'leaflet-map/leaflet-map.php' ) || ! is_plugin_active( 'extensions-for-leaflet-map/extensions-for-leaflet-map.php' ) ) {
        add_action( 'admin_notices', 'wpm_render_dependency_error_notice' );
        return; 
    }
    wpm_initialize_plugin_logic();
}

function wpm_render_dependency_error_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p><strong>wpmapper:</strong> <?php _e( 'Il plugin richiede Leaflet Map ed Extensions for Leaflet Map attivi per funzionare.', 'wpmapper' ); ?></p>
    </div>
    <?php
}

function wpm_initialize_plugin_logic() {
    add_action( 'admin_menu', 'wpm_admin_menu' );
    add_filter( 'bpglocation_leaflet_shortcode_string', 'wpm_inject_leaflet_shortcodes' );
    add_action( 'wp_enqueue_scripts', 'wpm_enqueue_drawing_tools' );
    add_action( 'admin_enqueue_scripts', 'wpm_enqueue_drawing_tools' );
    add_action( 'wp_ajax_wpm_save_new_layer', 'wpm_save_new_layer_callback' );
    add_action( 'wp_ajax_wpm_get_layer_geojson', 'wpm_get_layer_geojson_callback' );
}

function wpm_admin_menu() {
    add_menu_page( 'wpmapper', 'wpmapper', 'manage_options', 'wpmapper', 'wpm_render_settings_page', 'dashicons-map-alt2', 30 );
    add_submenu_page( 'wpmapper', __( 'Impostazioni wpmapper', 'wpmapper' ), __( 'Impostazioni', 'wpmapper' ), 'manage_options', 'wpmapper', 'wpm_render_settings_page' );
    add_submenu_page( 'wpmapper', __( 'Aggiungi Nuova Mappa', 'wpmapper' ), __( 'Aggiungi Mappa', 'wpmapper' ), 'manage_options', 'wpmapper-add', 'wpm_render_add_map_page' );
}

function wpm_inject_leaflet_shortcodes( $shortcode_string ) {
    return $shortcode_string . '[layers]';
}

function wpm_current_user_can_draw() {
    if ( current_user_can( 'manage_options' ) ) return true;
    if ( ! is_user_logged_in() ) return false;
    
    // Controllo basato sull'elenco esplicito degli ID utente consentiti nel pannello impostazioni
    $allowed_ids_str = get_option( 'wpm_allowed_user_ids', '' );
    if ( empty( $allowed_ids_str ) ) return false;
    $allowed_ids_array = array_map( 'intval', explode( ',', $allowed_ids_str ) );
    return in_array( get_current_user_id(), $allowed_ids_array, true );
}

// MANAGEMENT PANNELLO IMPOSTAZIONI E LISTA LAYER
function wpm_render_settings_page() {
    if ( isset($_GET['action']) && $_GET['action'] === 'delete_layer' && isset($_GET['layer_id']) ) {
        check_admin_referer( 'wpm_delete_layer_nonce' );
        $layers = get_option( 'wpm_community_layers', array() );
        $target_id = sanitize_text_field($_GET['layer_id']);
        if ( isset($layers[$target_id]) ) {
            unset($layers[$target_id]);
            update_option( 'wpm_community_layers', $layers );
            echo '<div class="updated"><p>' . __( 'Mappa rimossa con successo dall\'archivio.', 'wpmapper' ) . '</p></div>';
        }
    }

    if ( isset($_POST['wpm_save_settings']) ) {
        check_admin_referer( 'wpm_settings_nonce' );
        update_option( 'wpm_choropleth_url', sanitize_text_field($_POST['wpm_choropleth_url']) );
        update_option( 'wpm_raster_url', sanitize_text_field($_POST['wpm_raster_url']) );
        update_option( 'wpm_raster_bounds', sanitize_text_field($_POST['wpm_raster_bounds']) );
        
        $user_ids_raw = sanitize_text_field($_POST['wpm_allowed_user_ids']);
        $user_ids_clean = preg_replace('/\s+/', '', $user_ids_raw);
        update_option( 'wpm_allowed_user_ids', $user_ids_clean );
        echo '<div class="updated"><p>' . __( 'Impostazioni salvate!', 'wpmapper' ) . '</p></div>';
    }
    
    $choropleth_url   = get_option( 'wpm_choropleth_url', '' );
    $raster_url       = get_option( 'wpm_raster_url', '' );
    $raster_bounds    = get_option( 'wpm_raster_bounds', '' );
    $allowed_user_ids = get_option( 'wpm_allowed_user_ids', '' );
    $layers           = get_option( 'wpm_community_layers', array() );
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-map-alt2"></span> wpmapper &mdash; <?php _e( 'Hub e Impostazioni', 'wpmapper' ); ?></h1>
        
        <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px; margin-top: 20px;">
            <h2>📁 <?php _e( 'Mappe Salvate in Archivio', 'wpmapper' ); ?></h2>
            <?php if ( empty($layers) ) : ?>
                <p style="color: #666; font-style: italic;"><?php _e( 'Nessuna mappa salvata finora.', 'wpmapper' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th style="font-weight:bold; width:25%;"><?php _e( 'Titolo', 'wpmapper' ); ?></th>
                            <th style="font-weight:bold; width:35%;"><?php _e( 'Descrizione', 'wpmapper' ); ?></th>
                            <th style="font-weight:bold; width:15%;"><?php _e( 'Tipo di Ingestione', 'wpmapper' ); ?></th>
                            <th style="font-weight:bold; width:10%;"><?php _e( 'Autore', 'wpmapper' ); ?></th>
                            <th style="font-weight:bold; width:15%;"><?php _e( 'Azioni', 'wpmapper' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $layers as $id => $layer ) : 
                            $l_type = isset($layer['type']) ? $layer['type'] : 'vector';
                            $badge_color = '#fff';
                            if($l_type === 'external_url') $badge_color = '#dbeafe';
                            if($l_type === 'raster') $badge_color = '#fef3c7';
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($layer['name']); ?></strong></td>
                                <td><?php echo esc_html($layer['desc']); ?></td>
                                <td><code style="background:<?php echo $badge_color; ?>; padding:3px 6px; border-radius:3px;"><?php echo esc_html(strtoupper(str_replace('_', ' ', $l_type))); ?></code></td>
                                <td>👤 ID: <?php echo intval($layer['author']); ?></td>
                                <td>
                                    <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=wpmapper&action=delete_layer&layer_id=' . $id), 'wpm_delete_layer_nonce' ); ?>" style="color: #a00;" onclick="return confirm('Vuoi davvero eliminare questa mappa dall\'archivio pubblico?');"><?php _e( 'Elimina', 'wpmapper' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <form method="POST" action="">
            <?php wp_nonce_field( 'wpm_settings_nonce' ); ?>
            <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #11a8e5;">
                <h2>👥 <?php _e( 'ID Utenti Abilitati al Disegno Manuale Frontend', 'wpmapper' ); ?></h2>
                <input type="text" name="wpm_allowed_user_ids" value="<?php echo esc_attr($allowed_user_ids); ?>" style="width: 100%; font-family: monospace;" placeholder="Es: 4, 15, 23">
            </div>
            <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px;">
                <h2>📊 <?php _e( '1. Mappa Dati Istituzionale (GeoJSON URL Remoto)', 'wpmapper' ); ?></h2>
                <input type="text" name="wpm_choropleth_url" value="<?php echo esc_url($choropleth_url); ?>" style="width:100%;">
            </div>
            <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px;">
                <h2>🖼️ <?php _e( '2. Mappa Grafica Overlay Storico (Raster URL)', 'wpmapper' ); ?></h2>
                <input type="text" name="wpm_raster_url" value="<?php echo esc_url($raster_url); ?>" style="width:100%; margin-bottom:10px;">
                <input type="text" name="wpm_raster_bounds" value="<?php echo esc_attr($raster_bounds); ?>" style="width:100%; font-family:monospace;" placeholder="SW_lat,SW_lng,NE_lat,NE_lng">
            </div>
            <input type="submit" name="wpm_save_settings" class="button button-primary" value="<?php _e( 'Salva Configurazione', 'wpmapper' ); ?>">
        </form>
    </div>
    <?php
}

// SCHEDA DI ACQUISIZIONE E MAPPATURA NUOVE MAPPE (LOCALE / REMOTO / DISEGNO)
function wpm_render_add_map_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset($_POST['wpm_import_map_submit']) ) {
        check_admin_referer( 'wpm_import_nonce' );
        
        $title = sanitize_text_field($_POST['wpm_new_layer_title']);
        $desc  = sanitize_text_field($_POST['wpm_new_layer_desc']);
        $type  = sanitize_text_field($_POST['wpm_creation_method']);
        
        $is_choropleth_checked = isset($_POST['wpm_is_choropleth']) ? true : false;
        $choro_prop   = sanitize_text_field($_POST['wpm_choro_prop']);
        $choro_steps  = intval($_POST['wpm_choro_steps']);
        $choro_scale  = sanitize_text_field($_POST['wpm_choro_scale']);

        // Mappatura scale cromatiche standard basate su ColorBrewer / Leaflet Tutorial
        $colors_arr = array('#ffeda0', '#800026'); 
        if($choro_scale === 'blues') $colors_arr = array('#f7fbff', '#084594');
        if($choro_scale === 'greens') $colors_arr = array('#f7fcf5', '#00441b');

        $choropleth_settings = false;
        if ( $is_choropleth_checked ) {
            $choropleth_settings = array(
                'value_property' => !empty($choro_prop) ? $choro_prop : 'value',
                'steps'          => $choro_steps ? $choro_steps : 5,
                'scale'          => $colors_arr
            );
        }

        $geojson_content = '';
        $is_raster = false;
        $raster_url = '';
        $raster_bounds = '';
        $error = '';
        $is_external = false;
        $external_url = '';

        if ( empty($title) ) {
            $error = __( 'Il titolo della mappa è obbligatorio.', 'wpmapper' );
        } 
        // CASO A: URL ESTERNO ON THE FLY (SUPABASE / ZORNADE)
        elseif ( $type === 'external' ) {
            $external_url = esc_url_raw($_POST['wpm_external_url']);
            if ( empty($external_url) ) { $error = __( 'L\'URL del file remoto è obbligatorio per il caricamento on-the-fly.', 'wpmapper' ); }
            else { $is_external = true; }
        }
        // CASO B: DISEGNO SU TELA GEOMAN
        elseif ( $type === 'draw' ) {
            if ( ! empty($_POST['wpm_backend_draw_geojson']) ) { $geojson_content = stripslashes($_POST['wpm_backend_draw_geojson']); } 
            else { $error = __( 'Nessuna geometria tracciata sulla mappa.', 'wpmapper' ); }
        } 
        // CASO C: CARICAMENTO FILE IN LOCALE
        elseif ( $type === 'file' && ! empty($_FILES['wpm_uploaded_spatial_file']['tmp_name']) ) {
            $file_name = $_FILES['wpm_uploaded_spatial_file']['name'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $file_path = $_FILES['wpm_uploaded_spatial_file']['tmp_name'];

            if ( $file_ext === 'geojson' || $file_ext === 'json' ) { $geojson_content = file_get_contents($file_path); } 
            elseif ( $file_ext === 'kmz' ) {
                if ( class_exists('ZipArchive') ) {
                    $zip = new ZipArchive;
                    if ( $zip->open($file_path) === TRUE ) {
                        $kml_data = '';
                        for($i = 0; $i < $zip->numFiles; $i++) {
                            if ( strtolower(pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION)) === 'kml' ) { $kml_data = $zip->getFromIndex($i); break; }
                        }
                        $zip->close();
                        if (!empty($kml_data)) $geojson_content = wpm_parse_kml_to_geojson($kml_data);
                        else $error = __( 'Nessun file KML valido estratto dal pacchetto KMZ.', 'wpmapper' );
                    } else { $error = __( 'Impossibile aprire il file KMZ (Archivio corrotto).', 'wpmapper' ); }
                }
            } 
            elseif ( $file_ext === 'kml' ) { $geojson_content = wpm_parse_kml_to_geojson(file_get_contents($file_path)); } 
            elseif ( $file_ext === 'gpx' ) { $geojson_content = wpm_parse_gpx_to_geojson(file_get_contents($file_path)); } 
            elseif ( $file_ext === 'csv' ) { $geojson_content = wpm_parse_csv_to_geojson($file_path); } 
            elseif ( $file_ext === 'wkt' ) { $geojson_content = wpm_parse_wkt_to_geojson(file_get_contents($file_path)); } 
            elseif ( in_array($file_ext, array('jpg', 'jpeg', 'png')) ) {
                $bounds_input = sanitize_text_field($_POST['wpm_uploaded_image_bounds']);
                if ( empty($bounds_input) ) { $error = __( 'I limiti geografici (Bounds) sono obbligatori per ancorare le immagini raster.', 'wpmapper' ); } 
                else {
                    if ( ! function_exists('wp_handle_upload') ) require_once( ABSPATH . 'wp-admin/includes/file.php' );
                    $movefile = wp_handle_upload( $_FILES['wpm_uploaded_spatial_file'], array( 'test_form' => false ) );
                    if ( $movefile && ! isset($movefile['error']) ) { $is_raster = true; $raster_url = $movefile['url']; $raster_bounds = $bounds_input; } 
                    else { $error = $movefile['error']; }
                }
            } else { $error = __( 'Formato file non supportato dal motore GIS.', 'wpmapper' ); }
        }

        // SALVATAGGIO DEFINITIVO NEL DATABASE METADATI
        if ( empty($error) ) {
            $layers = get_option( 'wpm_community_layers', array() );
            $new_id = 'layer_admin_' . uniqid();

            if ( $is_external ) {
                $layers[$new_id] = array(
                    'name'   => $title, 'desc' => $desc, 'author' => get_current_user_id(),
                    'type'   => 'external_url', 'url' => $external_url
                );
                if($choropleth_settings) $layers[$new_id]['choropleth_settings'] = $choropleth_settings;
                update_option( 'wpm_community_layers', $layers );
                echo '<div class="updated"><p>' . __( 'Collegamento URL remoto salvato in archivio. Caricamento On-the-Fly pronto! 🚀', 'wpmapper' ) . '</p></div>';
            } 
            elseif ( $is_raster ) {
                $layers[$new_id] = array(
                    'name'   => $title, 'desc' => $desc, 'author' => get_current_user_id(),
                    'type'   => 'raster', 'url' => $raster_url, 'bounds' => $raster_bounds
                );
                update_option( 'wpm_community_layers', $layers );
                echo '<div class="updated"><p>' . __( 'Mappa grafica raster registrata correttamente.', 'wpmapper' ) . '</p></div>';
            } 
            else {
                $parsed_geo = json_decode($geojson_content, true);
                if ( json_last_error() === JSON_ERROR_NONE && !empty($parsed_geo['features']) ) {
                    $layers[$new_id] = array(
                        'name'   => $title, 'desc' => $desc, 'author' => get_current_user_id(),
                        'type'   => 'vector',
                        'geo'    => $parsed_geo
                    );
                    if($choropleth_settings) $layers[$new_id]['choropleth_settings'] = $choropleth_settings;
                    update_option( 'wpm_community_layers', $layers );
                    echo '<div class="updated"><p>' . __( 'Mappa vettoriale salvata con successo nell\'archivio locale.', 'wpmapper' ) . '</p></div>';
                } else { echo '<div class="error"><p>' . __( 'Geometrie assenti o non valide. Verifica l\'integrità dei dati.', 'wpmapper' ) . '</p></div>'; }
            }
        } else { echo '<div class="error"><p>' . esc_html($error) . '</p></div>'; }
    }
    ?>
    <div class="wrap">
        <h1>➕ <?php _e( 'Aggiungi Nuova Mappa nell\'Archivio', 'wpmapper' ); ?></h1>
        <form method="POST" action="" enctype="multipart/form-data" style="margin-top:20px;">
            <?php wp_nonce_field( 'wpm_import_nonce' ); ?>
            
            <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px;">
                <h2>📝 <?php _e( 'Metadati Informativi', 'wpmapper' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label><?php _e( 'Titolo Livello:', 'wpmapper' ); ?></label></th>
                        <td><input type="text" name="wpm_new_layer_title" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Descrizione Contesto:', 'wpmapper' ); ?></label></th>
                        <td><textarea name="wpm_new_layer_desc" style="width: 25em; height: 3em;"></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Metodo di acquisizione:', 'wpmapper' ); ?></label></th>
                        <td>
                            <label><input type="radio" name="wpm_creation_method" value="external" checked> <strong>🚀 <?php _e( 'Collegamento a URL Esterno (On the Fly - Consigliato per grandi Dataset Supabase/Zornade)', 'wpmapper' ); ?></strong></label><br><br>
                            <label><input type="radio" name="wpm_creation_method" value="file"> <strong>📂 <?php _e( 'Carica File Spaziale Vettoriale o Raster (Archivia su WordPress)', 'wpmapper' ); ?></strong></label><br><br>
                            <label><input type="radio" name="wpm_creation_method" value="draw"> <strong>✏️ <?php _e( 'Disegna le geometrie a mano adesso', 'wpmapper' ); ?></strong></label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- OPZIONE 1: CONFIGURAZIONE FONTE REMOTA -->
            <div id="wpm_admin_external_container" class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px;">
                <h2>🚀 <?php _e( 'Configurazione Endpoint Cloud Remoto', 'wpmapper' ); ?></h2>
                <p><?php _e( 'Inserisci il link directo al file GeoJSON ospitato esternamente. Il client scaricherà i dati al volo minimizzando il carico sulla CPU del tuo server.', 'wpmapper' ); ?></p>
                <input type="url" name="wpm_external_url" class="regular-text" style="width:100%" placeholder="https://tuo-bucket-supabase.co/storage/v1/object/public/.../roma.geojson">
            </div>

            <!-- BLOCCO TEMATIZZAZIONE COROPLETICA -->
            <div id="wpm_choropleth_wizard_card" class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #9333ea;">
                <h2>📊 <?php _e( 'Configurazione Tematizzazione Coropletica (Mappe Statistiche)', 'wpmapper' ); ?></h2>
                <p><label><input type="checkbox" id="wpm_is_choropleth" name="wpm_is_choropleth" value="1"> <strong><?php _e( 'Attiva gradiente di colore ad aree basato su dati interni', 'wpmapper' ); ?></strong></label></p>
                <div id="wpm_choropleth_subfields" style="display:none; margin-top:15px; padding-top:10px; border-top:1px solid #eee;">
                    <table class="form-table" style="margin:0;">
                        <tr><th style="width:220px;"><label><?php _e( 'Chiave Proprietà (Esatta nel file):', 'wpmapper' ); ?></label></th><td><input type="text" name="wpm_choro_prop" class="regular-text" placeholder="Es: reddito_medio, densita, abitanti"></td></tr>
                        <tr><th><label><?php _e( 'Numero di Fasce Cromatiche (Steps):', 'wpmapper' ); ?></label></th><td><input type="number" name="wpm_choro_steps" value="5" min="2" max="10" style="width:70px;"></td></tr>
                        <tr><th><label><?php _e( 'Gradiente di Scala Standard:', 'wpmapper' ); ?></label></th><td>
                            <select name="wpm_choro_scale">
                                <option value="classic"><?php _e( 'Classica Leaflet / ColorBrewer (Giallo ➔ Rosso)', 'wpmapper' ); ?></option>
                                <option value="blues"><?php _e( 'Ocean Blue (Bianco ➔ Blu)', 'wpmapper' ); ?></option>
                                <option value="greens"><?php _e( 'Forest Green (Bianco ➔ Verde)', 'wpmapper' ); ?></option>
                            </select>
                        </td></tr>
                    </table>
                </div>
            </div>

            <!-- OPZIONE 2: AREA DI DISEGNO BACKEND -->
            <div id="wpm_admin_draw_container" class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px; display:none;">
                <h2>✏️ <?php _e( 'Console di Disegno Vettoriale', 'wpmapper' ); ?></h2>
                <div id="wpm-admin-draw-map" style="height: 400px; width:100%; border:1px solid #ccc; border-radius:4px;"></div>
                <input type="hidden" id="wpm_backend_draw_geojson" name="wpm_backend_draw_geojson" value="">
            </div>

            <!-- OPZIONE 3: AREA FILE UPLOAD -->
            <div id="wpm_admin_file_container" class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px; display:none;">
                <h2>📂 <?php _e( 'Upload File Spaziale Localizzato', 'wpmapper' ); ?></h2>
                <p><?php _e( 'Estensioni accettate dal compilatore:', 'wpmapper' ); ?> <code>.geojson, .gpx, .kml, .kmz, .csv, .wkt, .jpg, .png</code></p>
                <input type="file" name="wpm_uploaded_spatial_file" accept=".geojson,.json,.gpx,.kml,.kmz,.csv,.wkt,.jpg,.jpeg,.png"><br><br>
                <div id="wpm_raster_bounds_field" style="display:none; border-left: 3px solid #ffb900; padding-left: 15px;">
                    <label><strong><?php _e( 'Coordinate Limiti Rettangolo Immagine (SW_lat,SW_lng,NE_lat,NE_lng):', 'wpmapper' ); ?></strong></label><br>
                    <input type="text" name="wpm_uploaded_image_bounds" class="regular-text" placeholder="Es: 41.802,12.401,41.952,12.602">
                </div>
            </div>

            <input type="submit" name="wpm_import_map_submit" class="button button-primary button-large" value="<?php _e( 'Archivia Mappa Definitivamente', 'wpmapper' ); ?>">
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const drawRadio = document.querySelector('input[value="draw"]');
        const fileRadio = document.querySelector('input[value="file"]');
        const extRadio  = document.querySelector('input[value="external"]');

        const drawBox = document.getElementById('wpm_admin_draw_container');
        const fileBox = document.getElementById('wpm_admin_file_container');
        const extBox  = document.getElementById('wpm_admin_external_container');
        
        const fileInput = document.querySelector('input[type="file"]');
        const boundsField = document.getElementById('wpm_raster_bounds_field');
        const choroCheck  = document.getElementById('wpm_is_choropleth');
        const choroFields = document.getElementById('wpm_choropleth_subfields');
        const choroWizardCard = document.getElementById('wpm_choropleth_wizard_card');

        function toggleAddView() {
            if(extRadio.checked) {
                extBox.style.display = 'block'; drawBox.style.display = 'none'; fileBox.style.display = 'none'; choroWizardCard.style.display = 'block';
            } else if(drawRadio.checked) { 
                drawBox.style.display = 'block'; fileBox.style.display = 'none'; extBox.style.display = 'none'; choroWizardCard.style.display = 'block';
            } else { 
                fileBox.style.display = 'block'; drawBox.style.display = 'none'; extBox.style.display = 'none';
                checkFileExtension(); 
            }
        }
        function checkFileExtension() {
            if(!fileInput.value) { choroWizardCard.style.display = 'block'; return; }
            const ext = fileInput.value.split('.').pop().toLowerCase();
            if(['jpg','jpeg','png'].includes(ext)) { boundsField.style.display = 'block'; choroWizardCard.style.display = 'none'; } 
            else { boundsField.style.display = 'none'; choroWizardCard.style.display = 'block'; }
        }

        drawRadio.addEventListener('change', toggleAddView);
        fileRadio.addEventListener('change', toggleAddView);
        extRadio.addEventListener('change', toggleAddView);
        fileInput.addEventListener('change', checkFileExtension);

        choroCheck.addEventListener('change', function() { choroFields.style.display = this.checked ? 'block' : 'none'; });
    });
    </script>
    <?php
}

// INNESTO FUNZIONI DI PARSING VETTORIALE XML / TXT
function wpm_parse_kml_to_geojson($kml){
    $xml = simplexml_load_string($kml); if(!$xml) return '{"type":"FeatureCollection","features":[]}';
    $features = array(); $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
    $placemarks = $xml->xpath('//Placemark') ? $xml->xpath('//Placemark') : $xml->xpath('//placemark');
    if(empty($placemarks)) $placemarks = array();
    foreach($placemarks as $pm){
        $name = (string)($pm->name ?? 'Elemento KML'); $geometry = null;
        if(isset($pm->Point->coordinates)){
            $c = explode(',', trim((string)$pm->Point->coordinates));
            if(count($c)>=2) $geometry = array('type'=>'Point','coordinates'=>array(floatval($c[0]),floatval($c[1])));
        } elseif(isset($pm->LineString->coordinates)){
            $geometry = array('type'=>'LineString','coordinates'=>wpm_clean_kml_coords((string)$pm->LineString->coordinates));
        } elseif(isset($pm->Polygon->outerBoundaryIs->LinearRing->coordinates)){
            $geometry = array('type'=>'Polygon','coordinates'=>array(wpm_clean_kml_coords((string)$pm->Polygon->outerBoundaryIs->LinearRing->coordinates)));
        }
        if($geometry) $features[] = array('type'=>'Feature','properties'=>array('name'=>$name,'description'=>$name),'geometry'=>$geometry);
    }
    return json_encode(array('type'=>'FeatureCollection','features'=>$features));
}

function wpm_clean_kml_coords($s){
    $o = array(); $pts = preg_split('/\s+/', trim($s));
    foreach($pts as $p){ $c = explode(',', $p); if(count($c)>=2) $o[] = array(floatval($c[0]),floatval($c[1])); } return $o;
}

function wpm_parse_gpx_to_geojson($gpx){
    $xml = simplexml_load_string($gpx); if(!$xml) return '{"type":"FeatureCollection","features":[]}';
    $features = array();
    foreach($xml->wpt as $wpt){ $features[] = array('type'=>'Feature','properties'=>array('name'=>(string)($wpt->name ?? 'Waypoint')),'geometry'=>array('type'=>'Point','coordinates'=>array(floatval($wpt['lon']),floatval($wpt['lat'])))); }
    foreach($xml->trk as $trk){
        $n = (string)($trk->name ?? 'Tracciato');
        foreach($trk->trkseg as $seg){ $coords = array(); foreach($seg->trkpt as $pt){ $coords[] = array(floatval($pt['lon']),floatval($pt['lat'])); } if(!empty($coords)) $features[] = array('type'=>'Feature','properties'=>array('name'=>$n),'geometry'=>array('type'=>'LineString','coordinates'=>$coords)); }
    }
    return json_encode(array('type'=>'FeatureCollection','features'=>$features));
}

function wpm_parse_csv_to_geojson($path){
    if(($handle = fopen($path, "r")) !== FALSE){
        $headers = array_map('strtolower', fgetcsv($handle, 1000, ","));
        $lat_idx = $lng_idx = $desc_idx = false;
        foreach($headers as $idx => $h){
            if(in_array($h, array('lat', 'latitude', 'y'))) $lat_idx = $idx;
            if(in_array($h, array('lng', 'longitude', 'lon', 'x'))) $lng_idx = $idx;
            if(in_array($h, array('name', 'title', 'value', 'desc'))) $desc_idx = $idx;
        }
        if($lat_idx===false || $lng_idx===false) return '{"type":"FeatureCollection","features":[]}';
        $features = array();
        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE){
            $lat = floatval($data[$lat_idx]); $lng = floatval($data[$lng_idx]);
            $desc = ($desc_idx!==false && isset($data[$desc_idx])) ? sanitize_text_field($data[$desc_idx]) : 'Punto CSV';
            if($lat && $lng) $features[] = array('type'=>'Feature','properties'=>array('name'=>$desc,'value'=>$desc),'geometry'=>array('type'=>'Point','coordinates'=>array($lng, $lat)));
        }
        fclose($handle); return json_encode(array('type'=>'FeatureCollection','features'=>$features));
    } return '{"type":"FeatureCollection","features":[]}';
}

function wpm_parse_wkt_to_geojson($wkt){
    $wkt = trim(strtoupper($wkt)); $features = array();
    if(preg_match('/^POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i', $wkt, $m)){
        $features[] = array('type'=>'Feature','properties'=>array('name'=>'Oggetto WKT'),'geometry'=>array('type'=>'Point','coordinates'=>array(floatval($m[1]),floatval($m[2]))));
    }elseif(preg_match('/^LINESTRING\s*\(([^)]+)\)/i', $wkt, $m)){
        $coords = array(); foreach(explode(',', trim($m[1])) as $p){ $pts = preg_split('/\s+/', trim($p)); if(count($pts)>=2) $coords[] = array(floatval($pts[0]),floatval($pts[1])); }
        if(!empty($coords)) $features[] = array('type'=>'Feature','properties'=>array('name'=>'Oggetto WKT'),'geometry'=>array('type'=>'LineString','coordinates'=>$coords));
    }
    return json_encode(array('type'=>'FeatureCollection','features'=>$features));
}

// CALLBACK ENDPOINT AJAX: SALVATAGGIO LAYER VETTORIALE FRONTEND (CROWDSOURCING)
function wpm_save_new_layer_callback(){
    check_ajax_referer('wpm_layer_nonce','nonce'); if(!wpm_current_user_can_draw()) wp_send_json_error();
    $name=sanitize_text_field($_POST['layer_name']); $desc=sanitize_text_field($_POST['layer_desc']); $geojson=json_decode(stripslashes($_POST['geojson']),true);
    if(empty($name)||empty($geojson)) wp_send_json_error();
    $layers=get_option('wpm_community_layers',array()); $new_id='layer_'.uniqid().'_'.rand(100,999);
    $layers[$new_id]=array('name'=>$name,'desc'=>$desc,'author'=>get_current_user_id(),'type'=>'vector','geo'=>$geojson);
    update_option('wpm_community_layers',$layers); wp_send_json_success(array('id'=>$new_id,'name'=>$name));
}

// CALLBACK ENDPOINT AJAX: DISTRIBUZIONE GEOMETRIE AL CLIENT ON-DEMAND
function wpm_get_layer_geojson_callback(){
    check_ajax_referer('wpm_layer_nonce','nonce'); $layer_id=sanitize_text_field($_POST['layer_id']); $layers=get_option('wpm_community_layers',array());
    if(isset($layers[$layer_id])) wp_send_json_success($layers[$layer_id]); wp_send_json_error();
}
