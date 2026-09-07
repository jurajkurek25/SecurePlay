(function () {
    "use strict";

    function initPlayer(video) {
        var src = video.getAttribute("data-sply-src");
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

        initWatermark(video);
    }

    // Shifts position every few seconds so a crop can't reliably remove
    // it. This is a leak deterrent (trace a leaked video back to who
    // watched it), not a way to block screen recording — nothing running
    // in the browser can do that.
    function initWatermark(video) {
        var text = video.getAttribute("data-sply-watermark");
        if (!text) {
            return;
        }

        var wrap = video.closest(".sply-player-wrap");
        if (!wrap) {
            return;
        }

        var mark = document.createElement("div");
        mark.className = "sply-watermark";
        mark.textContent = text;
        wrap.appendChild(mark);

        // Percent positions, kept out of the bottom ~18% where Plyr's
        // control bar lives.
        var positions = [
            { top: "6%", left: "6%" },
            { top: "6%", left: "70%" },
            { top: "40%", left: "6%" },
            { top: "40%", left: "60%" },
            { top: "70%", left: "35%" },
            { top: "20%", left: "40%" },
        ];
        var i = 0;

        function place() {
            var pos = positions[i % positions.length];
            mark.style.top = pos.top;
            mark.style.left = pos.left;
            i++;
        }

        place();
        setInterval(place, 8000);
    }

    function init() {
        var videos = document.querySelectorAll(".sply-player[data-sply-src]");
        videos.forEach(initPlayer);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
