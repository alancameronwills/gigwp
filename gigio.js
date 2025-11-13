

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
    })
}

// ********* Display gigs ************

/**
 * On loading the page, show the content
 * @param {json} gigListJson 
 */
function fillGigList(gigListJson) {
    const gigList = JSON.parse(gigListJson);
    let gigListHtml = gigList.map(gig => gigHtml(gig)).join("\n");
    gigio(".giglist>.gigs").innerHTML = gigListHtml;
    if (window?.setHandlers) setHandlers(gigioa(".gig"));
    if (gigio(".giglist").classList.contains("align-columns")) {
        window.gigsElementsInOrder = gigioa(".gig");
        rearrangeGigsByColumns();
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
    const title = (post.title?.rendered || post.title).replaceAll(/</g, "&lt;");
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
    if (post.meta.recursweeks) {
        const weeks = "" + post.meta.recursweeks; // cast from number
        let nth = [];
        if (weeks != "12345") {
            const cardinals = ['1st', '2nd', '3rd', '4th', 'last'];
            for (let i = 0; i < weeks.length; i++) {
                nth.push(cardinals[1 * weeks.charAt(i) - 1]);
            }
        }
        const nthString = nth.join(", ");
        const nthAndString = nthString.replace(/, ([^,]*)$/, " &amp; $1");
        gigdates += ` <span class='recurrence'>every ${nthAndString} week</span>`;
    }
    let bookbutton = "";
    if (post.meta.locallink || post.meta.bookinglink) {
        const link = post.meta.locallink
            ? post.link || "./?p=" + post.id
            : post.meta.bookinglink;
        bookbutton = `<button class="bookbutton" onclick="gotolink('${link}')">${post.meta.booklabel || window.gigiauDefaultBookButtonLabel || "Book"}</button>`;
    }
    let template = jQuery("#gigtemplate").html();
    let maps = {
        "gigid": post.id,
        "gigtitle": title,
        "gigpic": imgLink,
        "gigimg": imgElement,
        "gigdates": gigdates,
        "gigdtinfo": post.meta?.dtinfo || "",
        "bookbutton": bookbutton,
        "venue": post.meta?.venue || ""
    };

    if (window.gigTemplateEditingMap) {
        gigTemplateEditingMap(post, maps);
    }

    let show = template;
    Object.getOwnPropertyNames(maps).forEach(v => {
        show = show.replaceAll(`%${v}`, maps[v]);
    });
    return show.trim();
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
