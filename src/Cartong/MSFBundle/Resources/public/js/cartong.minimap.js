jQuery(document).ready(function() {
    jQuery('[data-toggle=tooltip]').tooltip();

    var element = jQuery('#minimap');
    if (element.length==0)
    {
        element = jQuery('.extentViewer');
    }
    if (element.length==0)
    {
        // No place to display map on this page.
        return;
    }
    
    var map = L.map(element[0], {
        zoomControl: false,
        attributionControl: false
    }).setView([0, 0], 13);
    element.data('map', map);

    var isInteractive = true;
    if (element.attr('data-map-interactive')==='false')
    {
        isInteractive = false;
    }
    
    L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    }).addTo(map);

    var globalBound = L.latLngBounds([]);

    var polygonOptions = {
        color: '#ee0000',
        fillColor: '#ee0000',
        weight: 2
    };
    // Manage the catalog case.
    jQuery('[data-geobox]').each(function() {
        var uuid = jQuery(this).attr('data-uuid');
        var geobox = jQuery(this).attr('data-geobox').split('|');
        geobox = _.map(geobox, function(value) {
            return parseFloat(value);
        });

        var polygon = L.polygon([
            [geobox[1], geobox[0]],
            [geobox[1], geobox[2]],
            [geobox[3], geobox[2]],
            [geobox[3], geobox[0]]
        ], polygonOptions).addTo(map);
        polygon.uuid = uuid;

        if (isInteractive)
        {
            var _this = jQuery(this);
            // Store polygon to enable document => minimap interactions.
            //_this.data('polygon', polygon);
            _this.on('mouseover', function() {
                polygon.setStyle({fillOpacity: 0.5});
            });
            _this.on('mouseout', function() {
                polygon.setStyle({fillOpacity: 0.2});
            });
            polygon.on('mouseover', function(e) {
                _this.css('opacity', 0.2);
            });
            polygon.on('mouseout', function(e) {
                _this.css('opacity', 1.0);
            });
            polygon.on('click', function(e) {
                _this.find('a')[0].click();
            });
        }

        globalBound.extend(polygon.getBounds());
    });
    // Manage the single metadata view case.
    jQuery('[watched_bbox]').each(function() {
        var geobox = jQuery(this).attr('watched_bbox').split(',');
        geobox = _.map(geobox, function(value) {
            return parseFloat(jQuery('#'+value).val());
        });

        var polygon = L.polygon([
            [geobox[1], geobox[0]],
            [geobox[1], geobox[2]],
            [geobox[3], geobox[2]],
            [geobox[3], geobox[0]]
        ], polygonOptions).addTo(map);

        globalBound.extend(polygon.getBounds());
    });

    map.fitBounds(globalBound);
    element.data('globalBound', globalBound);
});