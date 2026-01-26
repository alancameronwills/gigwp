

// ****** Expanding Images **********


function gigioExpandImages() {
    gigioa("img").forEach(i => i.addEventListener("click", e => {
        e.preventDefault();
        e.stopPropagation();
        gigio().expandedImage = e.target.classList.toggle("expand-image")
            ? e.target
            : null;
    }));
    document.addEventListener("keydown", e => {
        if (e.key == "Escape") {
            if (gigio().expandedImage) {
                gigio().expandedImage.classList.remove("expand-image");
                gigio().expandedImage = null;
            }
        }
    });
}

// **** Scroll horizontal strip

function scrollStripHandler() {
    gigioa(".sa_scrollButton").forEach(i => i.addEventListener("click", (function (e) {
        let direction = this.className.indexOf("sa_scrollerLeft") >= 0 ? 1 : -1;
        let gigs = jQuery(this).parent().children(".gigs")[0];
        if (gigs) kickSideways(gigs, direction);
    })));

    function kickSideways(target, direction) {
        window.inhibitClick = window.setTimeout(() => { window.inhibitClick = null; }, 500);
        let jtarget = jQuery(target);
        let width = target.clientWidth || 400;
        clearTimeout(window.smoothScrollTimeout);
        jtarget.addClass("smoothie");
        jtarget.scrollLeft(jtarget.scrollLeft() + (width / 2) * direction);
        window.smoothScrollTimeout = setTimeout(() => jtarget.removeClass("smoothie"), 500);
    }
}

// ********* Display gigs ************

/**
 * On loading the page, show the content
 * @param {json} gigListJson 
 */
function fillGigList(gigListJson, strip = false) {
    const gigList = JSON.parse(gigListJson);
    let gigListHtml = gigList.map(gig => gigHtml(gig)).join("\n");
    gigio(".giglist>.gigs").innerHTML = gigListHtml;
    if (window?.setHandlers) setHandlers(gigioa(".gig"));
    if (gigio(".giglist").classList.contains("align-columns")) {
        window.gigsElementsInOrder = gigioa(".gig");
        rearrangeGigsByColumns();
    }
    if (strip) {
        scrollStripHandler();
    }
    window.scrollTo(0, 0);
}

function rearrangeGigsByColumns(event) {
    if (!event) window.addEventListener("resize", rearrangeGigsByColumns);
    const columnCount = Math.max(Math.floor(window.innerWidth / (window.gigWidth || 340)), 1);
    let gigsTop = gigio(".giglist>.gigs");
    if (gigsTop.children.length == columnCount
        && gigsTop.children[0].classList.contains("gig-column")) {
        return;
    }
    let newColumns = [];

    for (let col = 0; col < columnCount; col++) {
        let columnElement = document.createElement("div", {});
        newColumns.push(columnElement);
        columnElement.classList.add("gig-column");
        columnElement.style.width = columnCount > 0 ? Math.floor(100 / columnCount - 3) + "%" : "100%";
        for (let j = col; j < window.gigsElementsInOrder.length; j += columnCount) {
            columnElement.append(window.gigsElementsInOrder[j]);
        }
    }
    gigsTop.replaceChildren();
    for (let col = 0; col < newColumns.length; col++) {
        gigsTop.append(newColumns[col]);
    }
}



/**
 * Map a gig object to a displayed event
 * @param {Gig} post 
 * @returns HTML string
 */
function gigHtml(post) {
    try {
        const title = ("" + (post.title?.rendered || post.title)).replaceAll(/</g, "&lt;");
        const imgLink = post.thumbnail_image || post.pic || "/?p=" + post.featured_media;
        const imgElement = `<div class="gigpic" style="position:relative;padding:0;">`
            + (post.smallpic ? `<img src="${post.smallpic}"  title="poster: ${title}"/>` : "")
            + `<img class="full ${post.smallpic ? 'overlay' : ''}" src="${post.pic}" title="poster: ${title}"/>
		</div>`;
        let gigdates = friendlyDate(post.meta.dtstart) +
            (post.meta.dtstart == post.meta.dtend ? "" : " - " + friendlyDate(post.meta.dtend, false));
        if (post.meta.recursfortnight) {
            gigdates += " <span class='recurrence'>every 14 days</span>";
        }
        let gigshortdate = friendlyDate(post.meta.dtstart);
        if (post.meta.recursweeks) {
            const weeks = "" + post.meta.recursweeks; // cast from number
            let nth = [];
            if (weeks != "12345") {
                const cardinals = ['1st', '2nd', '3rd', '4th', 'last'];
                for (let i = 0; i < weeks.length; i++) {
                    nth.push(cardinals[1 * weeks.charAt(i) - 1]);
                }
            }
            const nthString = nth.join(" + ");
            //const nthAndString = nthString.replace(/, ([^,]*)$/, " &amp; $1");
            gigdates += ` <span class='recurrence'>+ every ${nthString} week ${nthString ? "of month" : ""}</span><br/>`;
        }
        let bookbutton = "";
        if (post.meta.locallink || post.meta.bookinglink) {
            const link = post.meta.locallink
                ? post.link || "./?p=" + post.id
                : post.meta.bookinglink;
            bookbutton = `<button class="bookbutton" onclick="gotolink('${link}')">${post.meta.booklabel || window.gigiauDefaultBookButtonLabel || "Book"}</button>`;
        }
        let template = jQuery("#gigtemplate").html();
        // Strip HTML to get plain text content, preserving paragraph breaks as newlines
        let contentText = "";
        if (post.content) {
            let html = post.content;
            // Convert paragraph and line breaks to newlines before stripping HTML
            html = html.replace(/<\/p>\s*<p[^>]*>/gi, "\n");
            html = html.replace(/<br\s*\/?>/gi, "\n");
            html = html.replace(/<\/p>/gi, "\n");
            const tmp = document.createElement("div");
            tmp.innerHTML = html;
            contentText = tmp.textContent || tmp.innerText || "";
            contentText = contentText.replace(/\n\s*\n/g, "\n").trim();
        }

        let maps = {
            "gigid": post.id,
            "gigtitle": title,
            "gigpic": imgLink,
            "gigimg": imgElement,
            "gigdates": gigdates,
            "gigshortdate": gigshortdate,
            "gigdtinfo": post.meta?.dtinfo || "",
            "bookbutton": bookbutton,
            "venue": post.meta?.venue || "",
            "gigcontenttext": contentText,
            "gigeditlink": "",
            "giglocallink": post.meta?.locallink ? "true" : "",
            "giglink": post.link || ""
        };

        if (window.gigTemplateEditingMap) {
            gigTemplateEditingMap(post, maps);
        }

        let show = template;
        Object.getOwnPropertyNames(maps).forEach(v => {
            show = show.replaceAll(`%${v}`, maps[v]);
        });
        return show.trim();
    } catch (e) {
        return "-";
    }
}

function friendlyDate(d = "", day = true) {
    if (!d) return "";
    let options = {};
    if (day) {
        options['weekday'] = 'short';
    }
    Object.assign(options, { 'day': 'numeric', 'month': 'short', 'year': 'numeric' });
    return new Date(d).toLocaleDateString(undefined, options);
}
function gotolink(link) {
    window.open(link);
}

/**
 * Handle click on shortened content
 * - In editing mode: open WordPress editor
 * - In viewing mode with locallink: open gig page in new tab
 * - In viewing mode without locallink: toggle expanded content popup (non-hover devices only)
 */
function handleContentClick(element, event) {
    event.stopPropagation();

    // In editing mode, open WordPress editor
    if (gigio(".giglist").classList.contains("editing")) {
        if (window.openGigEditor) {
            openGigEditor(element, event);
        }
        return;
    }

    // In viewing mode with locallink, open gig page
    if (element.dataset.locallink === "true" && element.dataset.link) {
        window.open(element.dataset.link, "_blank");
        return;
    }

    // In viewing mode without locallink, toggle expanded content (for non-hover devices)
    // On hover devices, the popup is shown via CSS :hover
    const hasHover = window.matchMedia("(hover: hover)").matches;
    if (hasHover) return;

    const wrapper = element.closest(".gig-content-wrapper");

    // Close any other expanded content
    gigioa(".gig-content-wrapper.expanded").forEach(w => {
        if (w !== wrapper) w.classList.remove("expanded");
    });

    wrapper.classList.toggle("expanded");
}

/**
 * Handle click on expanded full content popup
 * - If locallink is true, open gig page in new tab
 * - Otherwise just stop propagation (keep popup open)
 */
function handleFullContentClick(element, event) {
    event.stopPropagation();

    if (element.dataset.locallink === "true" && element.dataset.link) {
        window.open(element.dataset.link, "_blank");
    }
}

/**
 * Close expanded content when clicking outside
 */
function setupContentClickOutside() {
    function closeExpanded(event) {
        const expanded = gigio(".gig-content-wrapper.expanded");
        if (!expanded) return;

        // Check if click is inside the expanded full content
        const fullContent = expanded.querySelector(".gig-content-full");
        if (fullContent && fullContent.contains(event.target)) return;

        expanded.classList.remove("expanded");
    }

    gigio().addEventListener("click", closeExpanded);
    document.addEventListener("click", closeExpanded);
}
