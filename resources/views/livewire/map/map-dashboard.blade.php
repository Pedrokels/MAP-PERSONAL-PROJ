<div>
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
    <div x-data="mapboxComponent()" x-init="initMap()" class="relative">
        <div class="flex flex-col gap-4 absolute top-5 right-5 z-10">
            <button x-on:click="loadSchools()" class="bg-white rounded-lg shadow-lg text-black p-2 hover:bg-amber-300">
                Load Schools
            </button>
            <button x-on:click="resetSchools()" class="bg-red-500 rounded-lg shadow-lg text-black p-2 hover:bg-amber-300">
                Reset
            </button>
        </div>


        <div id="map"></div>
    </div>


    <script>
        function mapboxComponent() {
            return {
                map: null,
                schoolsLoaded: false,

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

                    this.map.on('style.load', () => {
                        // Insert the layer beneath any symbol layer.
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

                                    // Use an 'interpolate' expression to
                                    // add a smooth transition effect to
                                    // the buildings as the user zooms in.
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

                        // code here recent
                    });
                },
                loadSchools() {
                    if (this.schoolsLoaded) return; // Prevent adding twice

                    // Check if source already exists, remove if so (defensive)
                    if (this.map.getSource('barangay-buildings')) {
                        this.map.removeLayer('barangay-buildings-layer');
                        this.map.removeSource('barangay-buildings');
                    }

                    this.map.addSource('barangay-buildings', {
                        'type': 'geojson',
                        'data': '/GeoData/BaguioBuildings.geojson'
                    });

                    this.map.addLayer({
                        'id': 'barangay-buildings-layer',
                        'type': 'fill-extrusion',
                        'source': 'barangay-buildings',
                        paint: {
                            'fill-extrusion-color': [
                                'match',
                                ['coalesce',
                                    ['get', 'building'],
                                    ['get', 'amenity'],
                                    ['get', 'shop']
                                ],
                                'school', 'green', // Only color schools green
                                /* other */
                                'black'
                            ],
                            'fill-extrusion-opacity': 1
                        },
                        filter: ['==', ['get', 'building'], 'school'] // Only show schools
                    });

                    this.schoolsLoaded = true;
                },

                resetSchools() {
                    // Remove the schools layer and source if they exist
                    if (this.map.getLayer('barangay-buildings-layer')) {
                        this.map.removeLayer('barangay-buildings-layer');
                    }
                    if (this.map.getSource('barangay-buildings')) {
                        this.map.removeSource('barangay-buildings');
                    }
                    this.schoolsLoaded = false;
                },
            };
        }
    </script>


</div>