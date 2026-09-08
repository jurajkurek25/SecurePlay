(function ($) {
    "use strict";

    $(function () {
        $("#sply-pmpro-activate-license").on("click", function () {
            var $btn = $(this);
            var $msg = $("#sply-pmpro-license-message");
            var key = $("#sply_pmpro_license_key").val();

            $btn.prop("disabled", true);
            $msg.text("…");

            $.post(splyPmproAdmin.ajaxUrl, {
                action: "sply_pmpro_activate_license",
                nonce: splyPmproAdmin.nonce,
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

        $("#sply-pmpro-deactivate-license").on("click", function () {
            var $btn = $(this);
            $btn.prop("disabled", true);

            $.post(splyPmproAdmin.ajaxUrl, {
                action: "sply_pmpro_deactivate_license",
                nonce: splyPmproAdmin.nonce,
            }).always(function () {
                window.location.reload();
            });
        });
    });
})(jQuery);
