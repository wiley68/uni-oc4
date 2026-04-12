function uniChangeContainer() {
    var uni_label_container = document.getElementsByClassName(
        "uni-label-container",
    )[0];
    if (!uni_label_container) {
        return;
    }
    if (uni_label_container.style.visibility == "visible") {
        uni_label_container.style.visibility = "hidden";
        uni_label_container.style.opacity = 0;
        uni_label_container.style.transition =
            "visibility 0s, opacity 0.5s ease";
    } else {
        uni_label_container.style.visibility = "visible";
        uni_label_container.style.opacity = 1;
    }
}
function uniGoTo() {
    var el = document.querySelector(".uni_float[data-uni-backurl]");
    var url = el ? el.getAttribute("data-uni-backurl") : "";
    if (url) {
        window.open(url, "_blank", "noopener,noreferrer");
    }
}

document.addEventListener("DOMContentLoaded", function () {
    var trigger = document.querySelector(".uni_float[data-uni-mode]");
    if (!trigger) {
        return;
    }

    trigger.addEventListener("click", function () {
        var mode = trigger.getAttribute("data-uni-mode");
        if (mode === "goto") {
            uniGoTo();
            return;
        }
        uniChangeContainer();
    });
});
