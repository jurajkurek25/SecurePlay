(function ($) {
    "use strict";

    $(function () {
        $(".sply-color-field").wpColorPicker();

        $("#sply-activate-license").on("click", function () {
            var $btn = $(this);
            var $msg = $("#sply-license-message");
            var key = $("#sply_license_key").val();

            $btn.prop("disabled", true);
            $msg.text("…");

            $.post(splyAdmin.ajaxUrl, {
                action: "sply_activate_license",
                nonce: splyAdmin.nonce,
                license_key: key,
            })
                .done(function (res) {
                    $msg.text(res.data && res.data.message ? res.data.message : "");
                    if (res.success) {
                        window.location.reload();
                    }
                })
                .fail(function () {
                    $msg.text("Something went wrong, please try again.");
                })
                .always(function () {
                    $btn.prop("disabled", false);
                });
        });

        $("#sply-deactivate-license").on("click", function () {
            var $btn = $(this);
            $btn.prop("disabled", true);

            $.post(splyAdmin.ajaxUrl, {
                action: "sply_deactivate_license",
                nonce: splyAdmin.nonce,
            }).always(function () {
                window.location.reload();
            });
        });

        $("#sply-save-settings").on("click", function () {
            var $btn = $(this);
            var $msg = $("#sply-settings-message");

            $btn.prop("disabled", true);
            $msg.text("…");

            $.post(splyAdmin.ajaxUrl, {
                action: "sply_save_settings",
                nonce: splyAdmin.nonce,
                default_color: $("#sply_default_color").val(),
                segment_duration: $("#sply_segment_duration").val(),
                ffmpeg_path: $("#sply_ffmpeg_path").val(),
            })
                .done(function (res) {
                    $msg.text(res.data && res.data.message ? res.data.message : "");
                })
                .fail(function () {
                    $msg.text("Something went wrong, please try again.");
                })
                .always(function () {
                    $btn.prop("disabled", false);
                });
        });
    });
})(jQuery);
