jQuery(document).ready(function($) {
    $("table.kbcal td.datetimecell").click(function() {
        $("#kbcal-dialog").dialog();
    });
});
/*
            var data = {
                    action: 'kbcal_action',
                    whatever: 1234
                };

                jQuery.post(ajaxurl, data, function(response) {
                    alert('Got:' + response);
                });

                       });
*/