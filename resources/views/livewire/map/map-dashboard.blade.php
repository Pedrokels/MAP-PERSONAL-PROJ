<div>
    <div x-data="mapboxComponent()" x-init="initMap()" class="relative">
        <style>
            #map {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
            }

            .mapboxgl-popup {
                max-width: 400px;
                font:
                    12px/20px 'Helvetica Neue',
                    Arial,
                    Helvetica,
                    sans-serif;
            }
        </style>
        <div class="flex flex-col gap-4 absolute top-5 right-5 z-10">
            <button x-on:click="resetMap()" class="bg-red-500 rounded-lg shadow-lg text-black p-2 hover:bg-amber-300">
                Reset
            </button>
            <div id="coordinates"
                class="fixed bottom-8 right-8 bg-white/90 rounded-lg shadow-lg text-black p-3 z-50 text-sm border border-gray-300"
                style="display:none;">
            </div>
            <div id="route-info"
                class="fixed bottom-20 right-8 bg-white/90 rounded-lg shadow-lg text-black p-3 z-50 text-sm border border-gray-300"
                style="display:none;">
            </div>
        </div>

        <div id="map"></div>
        @assets
        <script>
            function mapboxComponent() {
                return {
                    map: null,
                    hotelMarkers: [],
                    hotelPopups: [],
                    highlightedHotelsLayer: null,
                    selectedHotelIndex: null,
                    hotelRoutes: {},
                    nearestHotels: [],
                    startPoint: null,
                    routeTypes: {},
                    markerElements: [],
                    _pendingHotelQuery: null, // for debouncing
                    _isRenderingHotels: false, // for concurrency control

                    // --- Debounce utility for fast UI ---
                    _debounceTimer: null,
                    _debounce(fn, delay = 250) {
                        clearTimeout(this._debounceTimer);
                        this._debounceTimer = setTimeout(fn, delay);
                    },

                    initMap() {
                        if (!mapboxgl.supported()) {
                            alert('Your browser does not support Mapbox GL');
                            return;
                        }

                        mapboxgl.accessToken = '{{ config("services.mapbox.token") }}';
                        this.map = new mapboxgl.Map({
                            style: 'mapbox://styles/mapbox/dark-v11',
                            center: [120.5976, 16.4023],
                            zoom: 12.5,
                            pitch: 60,
                            bearing: 17.6,
                            container: 'map',
                            antialias: true
                        });

                        window.mapboxComponentInstance = this;

                        this.map.on('style.load', () => {
                            const layers = this.map.getStyle().layers;
                            const labelLayerId = layers.find(
                                (layer) => layer.type === 'symbol' && layer.layout['text-field']
                            ).id;

                            this.map.addLayer({
                                    'id': 'add-3d-buildings',
                                    'source': 'composite',
                                    'source-layer': 'building',
                                    'filter': ['==', 'extrude', 'true'],
                                    'type': 'fill-extrusion',
                                    'minzoom': 15,
                                    'paint': {
                                        'fill-extrusion-color': '#aaa',
                                        'fill-extrusion-height': [
                                            'interpolate',
                                            ['linear'],
                                            ['zoom'],
                                            15,
                                            0,
                                            15.05,
                                            ['get', 'height']
                                        ],
                                        'fill-extrusion-base': [
                                            'interpolate',
                                            ['linear'],
                                            ['zoom'],
                                            15,
                                            0,
                                            15.05,
                                            ['get', 'min_height']
                                        ],
                                        'fill-extrusion-opacity': 0.6
                                    }
                                },
                                labelLayerId
                            );

                            //   My Marker
                            const el = document.createElement('img');
                            el.src = '/images/PIN.png';
                            el.alt = 'Pin';
                            el.style.width = '50px';
                            el.style.height = '50px';
                            el.style.objectFit = 'contain';
                            el.style.pointerEvents = 'auto';

                            const marker = new mapboxgl.Marker({
                                    element: el,
                                    draggable: true,
                                    anchor: 'bottom'
                                })
                                .setLngLat([120.5976, 16.4023])
                                .addTo(this.map);

                            function toFourDecimalPlaces(num) {
                                return parseFloat(num.toFixed(4));
                            }

                            const coordinates = document.getElementById('coordinates');

                            // --- OPTIMIZED: Only render hotels/routes after flyTo/zoom is finished ---
                            function setMarkerAndFlyTo(lng, lat, zoomLevel = 14) {
                                marker.setLngLat([lng, lat]);
                                coordinates.style.display = 'block';
                                coordinates.innerHTML = `Longitude: ${toFourDecimalPlaces(lng)}<br />Latitude: ${toFourDecimalPlaces(lat)}`;
                                setTimeout(() => {
                                    coordinates.style.display = 'none';
                                }, 5000);

                                // Remove any pending hotel query
                                if (this._pendingHotelQuery) {
                                    this.map.off('moveend', this._pendingHotelQuery);
                                    this._pendingHotelQuery = null;
                                }

                                // Only render hotels/routes after flyTo/zoom is finished
                                this.map.flyTo({
                                    center: [lng, lat],
                                    zoom: zoomLevel,
                                    speed: 0.5,
                                    curve: 1.42,
                                    essential: true
                                });

                                // Debounce: Wait for moveend (flyTo finished) before rendering
                                this._pendingHotelQuery = () => {
                                    // Use debounce to avoid rapid calls
                                    this._debounce(() => {
                                        this.findAndRouteToNearestHotel(lng, lat);
                                        this.map.off('moveend', this._pendingHotelQuery);
                                        this._pendingHotelQuery = null;
                                    }, 100);
                                };
                                this.map.on('moveend', this._pendingHotelQuery);
                            }

                            function onDragEnd() {
                                const lngLat = marker.getLngLat();
                                setMarkerAndFlyTo.call(this, lngLat.lng, lngLat.lat, 17);
                            }

                            marker.on('dragend', onDragEnd.bind(this));
                        });
                    },

                    // --- Optimized: Debounced, minimal DOM updates, batch route fetches ---
                    findAndRouteToNearestHotel(lng, lat) {
                        // Prevent double calls if still pending
                        if (this._isRenderingHotels) return;
                        this._isRenderingHotels = true;

                        this.resetRoute();
                        const radius = 1000; // 1km
                        const overpassQuery =
                            `[out:json][timeout:25];
    (
        node["tourism"="hotel"](around:${radius},${lat},${lng});
        way["tourism"="hotel"](around:${radius},${lat},${lng});
    );
    out center;`
                        const overpassUrl = `https://overpass.kumi.systems/api/interpreter?data=${encodeURIComponent(overpassQuery)}`;

                        fetch(overpassUrl)
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error(`HTTP error! Status: ${response.status}`);
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.elements.length === 0) {
                                    alert('No hotels found within 1km.');
                                    this._isRenderingHotels = false;
                                    return;
                                }

                                const startPoint = new mapboxgl.LngLat(lng, lat);
                                this.startPoint = startPoint;
                                const hotelsWithDistances = data.elements
                                    .filter(el => el.type === 'way' || (el.type === 'node' && el.tags?.tourism === 'hotel'))
                                    .map(element => {
                                        const hotelCoords = element.type === 'way' ?
                                            (element.center ? [element.center.lon, element.center.lat] : null) : [element.lon, element.lat];

                                        if (!hotelCoords) return null;

                                        const hotelPoint = new mapboxgl.LngLat(hotelCoords[0], hotelCoords[1]);
                                        const distance = startPoint.distanceTo(hotelPoint);
                                        return {
                                            ...element,
                                            coords: hotelCoords,
                                            distance
                                        };
                                    })
                                    .filter(h => h !== null);

                                hotelsWithDistances.sort((a, b) => a.distance - b.distance);
                                const nearestHotels = hotelsWithDistances.slice(0, 5);
                                this.nearestHotels = nearestHotels;
                                this.routeTypes = {};

                                if (nearestHotels.length > 0) {
                                    const routeInfo = document.getElementById('route-info');
                                    routeInfo.style.display = 'block';
                                    // Only update the DOM once after all routes are fetched
                                    this.highlightHotelBuildings(nearestHotels, data.elements);

                                    // --- Optimization: Batch fetch all routes in parallel and update map in one go ---
                                    const processRoutesInParallel = async () => {
                                        // Pre-allocate hotel info array for fast join
                                        const hotelInfoArr = new Array(nearestHotels.length);
                                        const routePromises = [];
                                        for (let i = 0; i < nearestHotels.length; i++) {
                                            this.routeTypes[i] = 'driving-traffic';
                                            routePromises.push(
                                                this.getRoute(startPoint.toArray(), nearestHotels[i], i, (info) => {
                                                    hotelInfoArr[i] = info;
                                                }, 'driving-traffic')
                                            );
                                        }
                                        await Promise.all(routePromises);
                                        // Batch DOM update
                                        routeInfo.innerHTML = '<strong class="text-base">Top 5 Nearest Hotels:</strong>' + hotelInfoArr.join('');
                                        this.updateRouteTypeButtons();
                                        this._isRenderingHotels = false;
                                    };
                                    processRoutesInParallel();
                                } else {
                                    this._isRenderingHotels = false;
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching from Overpass API:', error);
                                alert(
                                    'Could not fetch hotel data. The Overpass API might be busy. Please try again in a moment.'
                                );
                                this._isRenderingHotels = false;
                            });
                    },

                    // --- Optimized: Only update source data, not re-adding layers if not needed ---
                    highlightHotelBuildings(hotels, allElements) {
                        const allNodes = {};
                        allElements.forEach(el => {
                            if (el.type === 'node') {
                                allNodes[el.id] = [el.lon, el.lat];
                            }
                        });

                        const geojsonFeatures = hotels.map((hotel, index) => {
                            if (hotel.type !== 'way') return null;
                            const coordinates = hotel.nodes.map(nodeId => allNodes[nodeId]).filter(Boolean);
                            if (coordinates.length < 4 || JSON.stringify(coordinates[0]) !== JSON.stringify(coordinates[coordinates.length - 1])) {
                                return null;
                            }

                            const routeColors = ['#FFD700', '#FF5733', '#33FF57', '#3357FF', '#FF33A1'];
                            const color = routeColors[index % routeColors.length];

                            return {
                                type: 'Feature',
                                properties: {
                                    ...hotel.tags,
                                    color: color
                                },
                                id: hotel.id,
                                geometry: {
                                    type: 'Polygon',
                                    coordinates: [coordinates]
                                }
                            };
                        }).filter(Boolean);

                        if (geojsonFeatures.length > 0) {
                            const sourceId = 'highlighted-hotels-source';
                            if (this.map.getSource(sourceId)) {
                                // Only update data, don't re-add source/layer
                                this.map.getSource(sourceId).setData({
                                    type: 'FeatureCollection',
                                    features: geojsonFeatures
                                });
                            } else {
                                this.map.addSource(sourceId, {
                                    type: 'geojson',
                                    data: {
                                        type: 'FeatureCollection',
                                        features: geojsonFeatures
                                    }
                                });
                            }

                            const layerId = 'highlighted-hotels-layer';
                            if (!this.map.getLayer(layerId)) {
                                this.map.addLayer({
                                    id: layerId,
                                    type: 'fill-extrusion',
                                    source: sourceId,
                                    paint: {
                                        'fill-extrusion-color': ['get', 'color'],
                                        'fill-extrusion-height': 25,
                                        'fill-extrusion-base': 0,
                                        'fill-extrusion-opacity': 0.75
                                    }
                                }, 'add-3d-buildings');
                                this.highlightedHotelsLayer = {
                                    source: sourceId,
                                    layer: layerId
                                };
                            }
                        }
                    },

                    // --- Optimized: Remove previous marker/layer only if exists, avoid duplicate DOM ops ---
                    async getRoute(start, hotel, index, onHotelInfo, routeType = 'driving-traffic') {
                        // Remove previous marker and popup for this hotel
                        if (this.hotelMarkers[index]) {
                            this.hotelMarkers[index].remove();
                            this.hotelMarkers[index] = null;
                        }
                        if (this.hotelPopups[index]) {
                            this.hotelPopups[index].remove();
                            this.hotelPopups[index] = null;
                        }
                        if (this.hotelRoutes[index]) {
                            const { routeId, sourceId } = this.hotelRoutes[index];
                            if (this.map.getLayer(routeId)) this.map.removeLayer(routeId);
                            if (this.map.getSource(sourceId)) this.map.removeSource(sourceId);
                            this.hotelRoutes[index] = null;
                        }

                        const end = hotel.type === 'way' ? [hotel.center.lon, hotel.center.lat] : [hotel.lon, hotel.lat];
                        const hotelName = hotel.tags.name || `Hotel #${index + 1}`;
                        const routeColors = ['#FFD700', '#FF5733', '#33FF57', '#3357FF', '#FF33A1'];
                        const color = routeColors[index % routeColors.length];
                        const directionsUrl =
                            `https://api.mapbox.com/directions/v5/mapbox/${routeType}/${start.join(',')};${end.join(',')}?geometries=geojson&overview=full&access_token=${mapboxgl.accessToken}`;
                        try {
                            const response = await fetch(directionsUrl);
                            const routeData = await response.json();
                            if (!routeData.routes || routeData.routes.length === 0) {
                                console.error(`No ${routeType} route found for hotel ${index + 1}.`);
                                return;
                            }
                            const route = routeData.routes[0].geometry;
                            const distance = routeData.routes[0].distance;
                            const duration = routeData.routes[0].duration;
                            const routeId = `route-${index}`;
                            const routeSourceId = `route-source-${index}`;
                            // Add route as a layer
                            this.map.addSource(routeSourceId, {
                                type: 'geojson',
                                data: {
                                    type: 'Feature',
                                    properties: {},
                                    geometry: route
                                }
                            });
                            this.map.addLayer({
                                id: routeId,
                                type: 'line',
                                source: routeSourceId,
                                layout: {
                                    'line-join': 'round',
                                    'line-cap': 'round'
                                },
                                paint: {
                                    'line-color': color,
                                    'line-width': 5,
                                    'line-opacity': 0.85
                                }
                            });
                            this.hotelRoutes[index] = {
                                type: routeType,
                                routeId,
                                sourceId: routeSourceId,
                                color
                            };
                            this.routeTypes[index] = routeType;

                            // --- Retain custom number marker (circle) for each hotel ---
                            const markerEl = document.createElement('div');
                            markerEl.style.backgroundColor = color;
                            markerEl.style.width = '28px';
                            markerEl.style.height = '28px';
                            markerEl.style.borderRadius = '50%';
                            markerEl.style.border = '2px solid white';
                            markerEl.style.color = 'white';
                            markerEl.style.textAlign = 'center';
                            markerEl.style.lineHeight = '24px';
                            markerEl.style.fontWeight = 'bold';
                            markerEl.style.fontSize = '18px';
                            markerEl.style.boxShadow = '0 2px 6px rgba(0,0,0,0.25)';
                            markerEl.innerText = index + 1;
                            markerEl.className = 'hotel-number-marker';

                            const hotelMarker = new mapboxgl.Marker(markerEl)
                                .setLngLat(end)
                                .addTo(this.map);
                            this.hotelMarkers[index] = hotelMarker;

                            const popupContent = `
                                <div class="p-1 text-black">
                                    <strong class="text-base">${index + 1}. ${hotelName}</strong>
                                    <hr class="my-1">
                                    <div class="text-sm">
                                        <span>Distance: ${(distance / 1000).toFixed(2)} km</span><br>
                                        <span>${this.routeTypeLabel(routeType)}: ${Math.round(duration / 60)} min</span>
                                    </div>
                                </div>
                            `;

                            const hotelPopup = new mapboxgl.Popup({
                                    closeButton: true,
                                    offset: 25
                                })
                                .setLngLat(end)
                                .setHTML(popupContent);

                            hotelMarker.setPopup(hotelPopup);
                            this.hotelPopups[index] = hotelPopup;

                            // Collect hotel info for batch DOM update
                            if (typeof onHotelInfo === 'function') {
                                onHotelInfo(`
        <div class='p-2 mt-2 border-t' id='hotel-info-${index}'>
            <strong style="color: ${color}; cursor:pointer;" onclick="window.selectHotelRoute(${index})">
                ${index + 1}. ${hotelName}
            </strong><br>
            <span class="text-sm">Distance: ${(distance / 1000).toFixed(2)} km</span>
            <span class="text-sm ml-2">${this.routeTypeLabel(routeType)}: ${Math.round(duration / 60)} min</span>
            <div class="flex gap-3 mt-2" id="route-type-btns-${index}">
                <div 
                    onclick="window.changeHotelRouteType(${index}, 'driving-traffic')" 
                    data-type="driving-traffic"
                    data-index="${index}"
                    class="route-type-btn flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer border border-blue-200 bg-blue-50 hover:bg-blue-100 hover:border-blue-400 transition-colors shadow-sm"
                    style="min-width: 90px;"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <rect x="3" y="11" width="18" height="6" rx="2" fill="#3B82F6" stroke="#1E40AF" stroke-width="1.5"/>
                        <circle cx="7" cy="18" r="2" fill="#1E40AF"/>
                        <circle cx="17" cy="18" r="2" fill="#1E40AF"/>
                        <rect x="7" y="7" width="10" height="4" rx="1" fill="#93C5FD"/>
                    </svg>
                    <span class="text-sm font-semibold text-blue-900">Car</span>
                </div>
                <div 
                    onclick="window.changeHotelRouteType(${index}, 'walking')" 
                    data-type="walking"
                    data-index="${index}"
                    class="route-type-btn flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer border border-green-200 bg-green-50 hover:bg-green-100 hover:border-green-400 transition-colors shadow-sm"
                    style="min-width: 90px;"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <circle cx="12" cy="5" r="2" fill="#22C55E"/>
                        <path d="M12 7v4l-2 2.5M12 11l2 2.5M10 13.5l-2 4.5M14 13.5l2 4.5" stroke="#16A34A" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M12 7v4" stroke="#16A34A" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span class="text-sm font-semibold text-green-900">Walk</span>
                </div>
                <div 
                    onclick="window.changeHotelRouteType(${index}, 'cycling')" 
                    data-type="cycling"
                    data-index="${index}"
                    class="route-type-btn flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer border border-yellow-200 bg-yellow-50 hover:bg-yellow-100 hover:border-yellow-400 transition-colors shadow-sm"
                    style="min-width: 90px;"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <circle cx="7" cy="17" r="3" fill="#FACC15"/>
                        <circle cx="17" cy="17" r="3" fill="#FACC15"/>
                        <path d="M7 17L12 7l5 10" stroke="#CA8A04" stroke-width="1.5" stroke-linecap="round"/>
                        <rect x="11" y="13" width="2" height="4" rx="1" fill="#FDE68A"/>
                    </svg>
                    <span class="text-sm font-semibold text-yellow-900">Bike</span>
                </div>
            </div>
        </div>
    `);
                            }

                        } catch (error) {
                            console.error(`Error fetching directions for hotel ${index + 1}:`, error);
                        }
                    },

                    routeTypeLabel(type) {
                        if (type === 'driving-traffic') return 'Car';
                        if (type === 'walking') return 'Walking';
                        if (type === 'cycling') return 'Cycling';
                        return type;
                    },

                    // --- Optimized: Only update changed routes/markers, batch highlight ---
                    highlightHotelRoute(index) {
                        Object.keys(this.hotelRoutes).forEach(i => {
                            const { routeId, color } = this.hotelRoutes[i];
                            this.map.setPaintProperty(routeId, 'line-color', i == index ? color : '#B0C4DE');
                            this.map.setPaintProperty(routeId, 'line-opacity', i == index ? 0.85 : 0.6);
                            // Highlight marker
                            if (this.hotelMarkers[i]) {
                                this.hotelMarkers[i].getElement().style.boxShadow = (i == index)
                                    ? '0 0 0 4px #fff, 0 2px 6px rgba(0,0,0,0.25)'
                                    : '0 2px 6px rgba(0,0,0,0.25)';
                                this.hotelMarkers[i].getElement().style.opacity = (i == index) ? '1' : '0.7';
                            }
                        });
                        this.selectedHotelIndex = index;
                        this.updateRouteTypeButtons();
                    },

                    // --- Optimized: Only update classes if changed ---
                    updateRouteTypeButtons() {
                        for (let i = 0; i < 5; i++) {
                            const btns = document.querySelectorAll(`#route-type-btns-${i} .route-type-btn`);
                            btns.forEach(btn => {
                                if (parseInt(btn.dataset.index) === this.selectedHotelIndex && btn.dataset.type === this.routeTypes[i]) {
                                    if (!btn.classList.contains('active')) btn.classList.add('active');
                                } else {
                                    btn.classList.remove('active');
                                }
                            });
                        }
                    },

                    // --- Optimized: Remove all layers/markers/popups in batch ---
                    resetRoute() {
                        Object.values(this.hotelRoutes).forEach(r => {
                            if (r && this.map.getLayer(r.routeId)) {
                                this.map.removeLayer(r.routeId);
                            }
                            if (r && this.map.getSource(r.sourceId)) {
                                this.map.removeSource(r.sourceId);
                            }
                        });
                        this.hotelRoutes = {};

                        this.hotelMarkers.forEach(marker => marker && marker.remove());
                        this.hotelMarkers = [];

                        this.hotelPopups.forEach(popup => popup && popup.remove());
                        this.hotelPopups = [];

                        if (this.highlightedHotelsLayer) {
                            if (this.map.getLayer(this.highlightedHotelsLayer.layer)) {
                                this.map.removeLayer(this.highlightedHotelsLayer.layer);
                            }
                            if (this.map.getSource(this.highlightedHotelsLayer.source)) {
                                this.map.removeSource(this.highlightedHotelsLayer.source);
                            }
                            this.highlightedHotelsLayer = null;
                        }

                        const routeInfo = document.getElementById('route-info');
                        if (routeInfo) {
                            routeInfo.style.display = 'none';
                            routeInfo.innerHTML = '';
                        }
                        this.selectedHotelIndex = null;
                        this.routeTypes = {};
                    }
                };
            }
            // --- Optimized: Only update route type if changed, batch highlight ---
            window.changeHotelRouteType = function(index, type) {
                const mapbox = window.mapboxComponentInstance;
                if (!mapbox || !mapbox.nearestHotels || !mapbox.startPoint) return;
                if (mapbox.routeTypes[index] === type) {
                    mapbox.highlightHotelRoute(index);
                    return;
                }
                const hotel = mapbox.nearestHotels[index];
                const start = mapbox.startPoint.toArray();
                mapbox.getRoute(start, hotel, index, null, type).then(() => {
                    mapbox.highlightHotelRoute(index);
                });
            };
            window.selectHotelRoute = function(index) {
                const mapbox = window.mapboxComponentInstance;
                if (!mapbox) return;
                mapbox.highlightHotelRoute(index);
            };
        </script>
        @endassets
    </div>
</div>