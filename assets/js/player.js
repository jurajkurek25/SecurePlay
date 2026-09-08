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
        initChapters(video);
    }

    // YouTube-style chapters: a clickable list under the player plus
    // markers on the progress bar, both built from the wrap's
    // data-sply-chapters JSON. Markers are appended into Plyr's own
    // .plyr__progress element, which Plyr already renders as
    // position:relative, so percentage-based absolute positioning lines
    // up with the seek bar without touching Plyr's own markup.
    function initChapters(video) {
        var wrap = video.closest(".sply-player-wrap");
        if (!wrap) {
            return;
        }

        var raw = wrap.getAttribute("data-sply-chapters");
        if (!raw) {
            return;
        }

        var chapters;
        try {
            chapters = JSON.parse(raw);
        } catch (e) {
            return;
        }
        if (!Array.isArray(chapters) || chapters.length === 0) {
            return;
        }

        var list = document.createElement("div");
        list.className = "sply-chapters-list";

        var items = chapters.map(function (chapter) {
            var item = document.createElement("button");
            item.type = "button";
            item.className = "sply-chapter";

            var time = document.createElement("span");
            time.className = "sply-chapter-time";
            time.textContent = formatChapterTime(chapter.time);

            var title = document.createElement("span");
            title.className = "sply-chapter-title";
            title.textContent = chapter.title;

            item.appendChild(time);
            item.appendChild(title);
            item.addEventListener("click", function () {
                video.currentTime = chapter.time;
                video.play();
            });

            list.appendChild(item);
            return item;
        });

        wrap.appendChild(list);

        var markers = [];

        function placeMarkers() {
            var progress = wrap.querySelector(".plyr__progress");
            if (!progress || !video.duration) {
                return;
            }

            markers.forEach(function (marker) {
                marker.remove();
            });

            markers = chapters.map(function (chapter) {
                var marker = document.createElement("span");
                marker.className = "sply-chapter-marker";
                marker.style.left = Math.min(100, (chapter.time / video.duration) * 100) + "%";
                marker.title = chapter.title;
                marker.addEventListener("click", function (e) {
                    e.stopPropagation();
                    video.currentTime = chapter.time;
                });
                progress.appendChild(marker);
                return marker;
            });
        }

        // With hls.js, "loadedmetadata" can fire before video.duration is
        // actually known (HLS duration comes from the manifest
        // asynchronously) — placeMarkers() no-ops until duration is a real
        // number, so keep listening on "durationchange" too rather than
        // a single one-time listener that could fire too early and never
        // retry.
        placeMarkers();
        video.addEventListener("loadedmetadata", placeMarkers);
        video.addEventListener("durationchange", placeMarkers);

        video.addEventListener("timeupdate", function () {
            var activeIndex = -1;
            for (var i = 0; i < chapters.length; i++) {
                if (video.currentTime >= chapters[i].time) {
                    activeIndex = i;
                }
            }
            items.forEach(function (item, i) {
                item.classList.toggle("is-active", i === activeIndex);
            });
            markers.forEach(function (marker, i) {
                marker.classList.toggle("is-active", i === activeIndex);
            });
        });
    }

    function formatChapterTime(totalSeconds) {
        var h = Math.floor(totalSeconds / 3600);
        var m = Math.floor((totalSeconds % 3600) / 60);
        var s = Math.floor(totalSeconds % 60);
        var ss = s < 10 ? "0" + s : String(s);
        if (h > 0) {
            var mm = m < 10 ? "0" + m : String(m);
            return h + ":" + mm + ":" + ss;
        }
        return m + ":" + ss;
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
