(function () {
    "use strict";

    function initPlayer(video) {
        var src = video.getAttribute("data-sdsp-src");
        if (!src) {
            return;
        }

        var plyr = new Plyr(video, {
            controls: [
                "play-large",
                "play",
                "progress",
                "current-time",
                "duration",
                "mute",
                "volume",
                "settings",
                "pip",
                "fullscreen",
            ],
            settings: ["quality", "speed"],
        });

        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls();
            hls.loadSource(src);
            hls.attachMedia(video);
        } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
            // Safari has native HLS + AES-128 key loading, no hls.js needed.
            video.src = src;
        }

        video.addEventListener("contextmenu", function (e) {
            e.preventDefault();
        });
    }

    function init() {
        var videos = document.querySelectorAll(".sdsp-player[data-sdsp-src]");
        videos.forEach(initPlayer);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
