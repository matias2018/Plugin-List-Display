// V.2.9.2
window.matchMedia("print").addEventListener("change", evt => {
    if (evt.matches) {
        const elms = document.body.querySelectorAll("details:not([open])");
        for (const e of elms) {
            e.setAttribute("open", "");
            e.dataset.wasclosed = "";
        }
    } else {
        const elms = document.body.querySelectorAll("details[data-wasclosed]");
        for (const e of elms) {
            e.removeAttribute("open");
            delete e.dataset.wasclosed;
        }
    }
});
