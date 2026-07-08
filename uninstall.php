<?php
/**
 * file di disinstallazione e pulizia per wpmapper
 * Rimuove in modo permanente tutte le opzioni registrate dal plugin.
 */

// Se WordPress non sta invocando direttamente la cancellazione, blocca l'accesso
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Lista delle opzioni memorizzate nel database da epurare
$wpm_options = array(
    'wpm_choropleth_url',
    'wpm_raster_url',
    'wpm_raster_bounds',
    'wpm_allowed_user_ids',
    'wpm_editable_geojson'
);

foreach ( $wpm_options as $option ) {
    delete_option( $option );
}
