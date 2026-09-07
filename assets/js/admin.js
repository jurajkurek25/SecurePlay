(function ($) {
    "use strict";

    $(function () {
        $(".sdsp-color-field").wpColorPicker();

        $("#sdsp-activate-license").on("click", function () {
            var $btn = $(this);
            var $msg = $("#sdsp-license-message");
            var key = $("#sdsp_license_key").val();

            $btn.prop("disabled", true);
            $msg.text("…");

            $.post(sdspAdmin.ajaxUrl, {
                action: "sdsp_activate_license",
                nonce: sdspAdmin.nonce,
                license_key: key,
            })
                .done(function (res) {
                    $msg.text(res.data && res.data.message ? res.data.message : "");
                    if (res.success) {
                        window.location.reload();
                    }
                })
                .fail(function () {
                    $msg.text("Nastala chyba, skús to prosím znova.");
                })
                .always(function () {
                    $btn.prop("disabled", false);
                });
        });

        $("#sdsp-deactivate-license").on("click", function () {
            var $btn = $(this);
            $btn.prop("disabled", true);

            $.post(sdspAdmin.ajaxUrl, {
                action: "sdsp_deactivate_license",
                nonce: sdspAdmin.nonce,
            }).always(function () {
                window.location.reload();
            });
        });

        $("#sdsp-save-settings").on("click", function () {
            var $btn = $(this);
            var $msg = $("#sdsp-settings-message");

            $btn.prop("disabled", true);
            $msg.text("…");

            $.post(sdspAdmin.ajaxUrl, {
                action: "sdsp_save_settings",
                nonce: sdspAdmin.nonce,
                default_color: $("#sdsp_default_color").val(),
                segment_duration: $("#sdsp_segment_duration").val(),
                ffmpeg_path: $("#sdsp_ffmpeg_path").val(),
            })
                .done(function (res) {
                    $msg.text(res.data && res.data.message ? res.data.message : "");
                })
                .fail(function () {
                    $msg.text("Nastala chyba, skús to prosím znova.");
                })
                .always(function () {
                    $btn.prop("disabled", false);
                });
        });
    });
})(jQuery);
