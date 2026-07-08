/**
 * wpmapper - Gestione Disegno Cooperativo Localizzato
 * Sviluppato da Socialforger
 */
document.addEventListener('DOMContentLoaded', function() {

    function initWpMapperDrawing(map) {
        if (!map || typeof wpmSettings === 'undefined') return;

        // 1. Rendering geometrie attive ricevute filtrate dal server
        const editableLayer = L.geoJson(wpmSettings.drawn_features, {
            onEachFeature: function(feature, layer) {
                if (feature.properties && feature.properties.description) {
                    let popupText = feature.properties.description;
                    
                    if (feature.properties.expiry) {
                        const timeLeft = Math.round((feature.properties.expiry - Math.floor(Date.now() / 1000)) / 60);
                        if (timeLeft > 0) {
                            popupText += `<br><small style="color:#d32f2f; font-weight:bold;">${wpmSettings.msg_expired_in} ${timeLeft} ${wpmSettings.msg_minutes}</small>`;
                        }
                    }
                    layer.bindPopup(popupText);
                }
            }
        }).addTo(map);

        map.eachControl(function(control) {
            if (control instanceof L.Control.Layers) {
                control.addOverlay(editableLayer, "✏️ Livello Editabile");
            }
        });

        // 2. Toolbar di disegno per utenti abilitati
        if (wpmSettings.can_draw && typeof map.pm !== 'undefined') {
            map.pm.addControls({
                position: 'topleft',
                drawMarker: true, drawPolyline: true, drawRectangle: true, drawPolygon: true, drawCircle: true, editMode: true, removalMode: true
            });

            function saveShapes() {
                const geojson = editableLayer.toGeoJSON();
                jQuery.ajax({
                    url: wpmSettings.ajax_url,
                    type: 'POST',
                    data: { action: 'wpm_save_geometry', nonce: wpmSettings.nonce, geojson: JSON.stringify(geojson) }
                });
            }

            map.on('pm:create', function(e) {
                const layer = e.layer;
                const desc = prompt(wpmSettings.msg_prompt);
                const isTemporary = confirm(wpmSettings.msg_temp);
                
                let expiryTimestamp = null;
                let finalDesc = desc || "Nota";

                if (isTemporary) {
                    const hoursStr = prompt(wpmSettings.msg_hours, "24");
                    const hours = parseInt(hoursStr, 10);
                    
                    if (!isNaN(hours) && hours > 0) {
                        expiryTimestamp = Math.floor(Date.now() / 1000) + (hours * 3600);
                        finalDesc += `<br><small style="color:#d32f2f; font-weight:bold;">${wpmSettings.msg_label_temp}</small>`;
                    }
                }

                layer.bindPopup(finalDesc);
                layer.feature = layer.feature || { type: "Feature", properties: {} };
                layer.feature.properties.description = desc;
                if (expiryTimestamp) {
                    layer.feature.properties.expiry = expiryTimestamp;
                }

                editableLayer.addLayer(layer);
                saveShapes();
            });

            map.on('pm:remove', function(e) {
                editableLayer.removeLayer(e.layer);
                saveShapes();
            });

            map.on('pm:globaleditmodetoggled', function(e) {
                if (!e.enabled) saveShapes();
            });
        }
    }

    function bindToExistingLeafletMap() {
        if (typeof WPLeafletMapPlugin !== 'undefined' && WPLeafletMapPlugin.maps) {
            WPLeafletMapPlugin.maps.forEach(function(mapInstance) {
                if (!mapInstance._wpm_attached) {
                    initWpMapperDrawing(mapInstance);
                    mapInstance._wpm_attached = true;
                }
            });
        }
        if (typeof window.bpglNativeMapInstance !== 'undefined') {
            if (!window.bpglNativeMapInstance._wpm_attached) {
                initWpMapperDrawing(window.bpglNativeMapInstance);
                window.bpglNativeMapInstance._wpm_attached = true;
            }
        }
    }

    setInterval(bindToExistingLeafletMap, 1000);
});
