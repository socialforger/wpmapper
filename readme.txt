=== wpmapper ===
Contributors: Socialforger
Tags: maps, buddyboss, leaflet, geoman, geojson, raster, choropleth
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.4.0
License: GPLv2 or later

Estensione nativa per bpglocation e l'ecosistema Leaflet Map. Aggiunge mappe statistiche coropletiche, overlay di piantine raster e strumenti di disegno collaborativo a tempo.

== Description ==

wpmapper estende la mappa dei gruppi BuddyBoss iniettando livelli GIS senza duplicare le librerie core. Consente di caricare mappe del reddito o della densità abitativa (GeoJSON), di sovrapporre piantine grafiche e planimetrie (Raster) e di offrire strumenti di moderazione cartografica a singoli utenti della community. Supporta scadenze orarie automatizzate per i marker temporanei.

== Installation ==

1. Carica la cartella `wpmapper` all'interno della directory `/wp-content/plugins/`.
2. Assicurati che i plugin `Leaflet Map` ed `Extensions for Leaflet Map` siano attivi.
3. Attiva il plugin dal menu 'Plugin' di WordPress.
4. Naviga in *Impostazioni -> wpmapper* per configurare i tuoi layer statici e gli ID utente.

== Changelog ==

= 1.4.0 =
* Aggiunta l'internazionalizzazione e la predisposizione per la cartella dei linguaggi.
* Integrazione dell'uninstaller nativo completo.
* Ottimizzazione del controllo scadenze temporanee lato server.

= 1.3.0 =
* Aggiunto controllo di disegno per singoli ID utente BuddyBoss.

= 1.0.0 =
* Rilascio iniziale della struttura a tre livelli cartografici.
