var gigUpdateHandlers = [];


// ************* Editing gigs ***********
/**
 * On clicking help button
 * @param {*} event 
 */
function helpGigs(event) {
    event?.stopPropagation?.();
    event?.preventDefault?.();
    window.open("https://github.com/alancameronwills/gigwp/blob/main/README.md", "help", "");
}

/**
 * Show new post on page without reloading page
 * @param (post) post 
 */
function insertGig(post) {
    let postDom = jQuery.parseHTML(gigHtml(post))[0];
    gigio(".giglist>.gigs").prepend(postDom);
    setHandlers(postDom);
    setFieldsEditable();
    return postDom;
}

/**
 * Redisplay after editing
 * @param {} post 
 */
function refreshGig(gig, post) {
    let html = gigHtml(post);
    let postDom = jQuery.parseHTML(html)[0];
    gig.replaceWith(postDom);
    setHandlers(postDom);
    setFieldsEditable();
    return postDom;
}

/**
 * Edit button clicked. Enable input fields.
 */
function editGig(event, on) {
    event?.stopPropagation?.();
    event?.preventDefault?.();
    gigio(".giglist").classList.toggle("editing", on);
    setFieldsEditable();
}
function setFieldsEditable() {
    if (gigio(".giglist").classList.contains("editing")) {
        gigioa("div.gig-field").forEach(div => div.contentEditable = true);
        gigioa("input.gig-field").forEach(div => div.disabled = false);
        gigio("#editButton").innerText = ("Done");
    } else {
        gigioa(".gig-field").forEach(div => div.contentEditable = false);
        gigioa("input.gig-field").forEach(div => div.disabled = true);
        gigio("#editButton").innerText = ("Edit");
    }
}

/**
 * Add button clicked. User chooses posters.
 */
function addGig(event) {
    event?.stopPropagation?.();
    event?.preventDefault?.();
    openMediaPopup();
}

/**
 * Save event poster to WordPress
 * @param {string} title Title of event
 * @param {number} img Poster image ID
 * @param {YYYY-MM-DD} dtstart 
 * @param {YYYY-MM-DD} dtend 
 * @param {string} dtinfo or venue
 * @returns void
 */
function newPost(title, img, dtstart = "", dtend = "", dtinfo = "") {
    if (!window.gigiauCategoryId) return;
    threadFlag(1);
    const today = new Date().toISOString().substring(0, 10);
    if (!dtstart || !(today.localeCompare(dtstart) < 0)) {
        dtstart = today;
    }

    if (!dtend || dtend.localeCompare(dtstart) < 0) {
        dtend = dtstart;
    }

    const query = {
        title: title.trim(),
        featured_media: img,
        content: "",
        status: 'publish',
        categories: [window.gigiauCategoryId],
        meta: {
            'dtstart': dtstart,
            'dtend': dtend,
            "recursday": 0
        }
    };
    // Parameter from shortcode:
    const m = dtinfo.match(/^([^=£]*)=?(.*)$/);
    if (m) {
        query.meta.venue = m[1];
        query.meta.dtinfo = m[2] || "";
    } else {
        if (window.gigiauVenueInFilename) {
            query.meta.venue = dtinfo;
        } else {
            query.meta.dtinfo = dtinfo;
        }
    }


    // https://developer.wordpress.org/rest-api/using-the-rest-api/backbone-javascript-client/
    const post = new wp.api.models.Post(query);
    post.save().done(confirmedPost => {
        confirmedPost.meta = query.meta; // confirmed doesn't return meta
        fetch(`${location.origin}/wp-json/wp/v2/media/${confirmedPost.featured_media}`)
            .then(r => r.json()).then(r => {
                confirmedPost.pic = r.guid?.rendered;
                insertGig(confirmedPost);
                threadFlag(-1, () => {
                    jQuery('html, body').animate({
                        scrollTop: jQuery("gigio-capsule").offset().top
                    }, 2000);
                    editGig(null, true);
                });
            })
            .catch(x => {
                threadFlag(-1, () => {
                    location.reload();
                }
                );
            })
    })
        .catch(e => {
            console.log("newPost: " + e);
            threadFlag(-1);
        });
}


// ******** Flag work in progress *******

function beforeUnloadHandler(event) {
    event.preventDefault();
    event.returnValue = "Still uploading changes";
    return true;
}

var threads = 0;

function threadFlag(count, f) {
    threads += count;
    const flag = gigio(".giglist");
    if (threads > 0) {
        flag.classList.add("edit-in-progress");
        // https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event
        window.addEventListener("beforeunload", beforeUnloadHandler);
    } else {
        flag.classList.remove("edit-in-progress");
        window.removeEventListener("beforeunload", beforeUnloadHandler);
        if (f) f();
    }
}

// *****************************

var mediaPopup;
var recentUploads = {};

/**
 * Add button clicked. User chooses posters.
 */
function openMediaPopup() {
    if (!mediaPopup) {
        mediaPopup = wp.media({
            title: "Select gig posters",
            library: {
                type: "image"
            },
            multiple: true,
            button: {
                text: "Add gigs"
            }
        });
        var self = this;
        mediaPopup.on("select", function (...args) {
            const imagepages = this.models[0].attributes.selection.models;
            const images = imagepages.map(ip => ip.attributes);
            // title,url,id,caption,description,date, editLink, height,width,filesizeInBytes,id,link,mime,name,sizes[]

            const imageSet = images.filter(img => {
                return recentUploads.hasOwnProperty(img.id) ? false : recentUploads[img.id] = true;
            });
            imageSet.forEach(img => {
                let dtstart = dtend = dtinfo = "";
                let title = "" + Date.now();
                try {
                    // You can put dates and info in the caption: YYYY-MM-DD YYYY-MM-DD info
                    if (img.caption?.trim()) {
                        title = img.title;
                        [dtstart, dtend, dtinfo] = readDates(img.caption);
                    } else {
                        // Or in the filename: title YYYY-MM-DD [YYYY-MM-DD] [HH-MM] [info]
                        [title, dtstart, dtend, dtinfo] = parseFN(img.title);
                    }
                } catch (e) {
                    console.log("Reading dates from filename or caption: ", e);
                }
                newPost(title, img.id, dtstart, dtend, dtinfo);
            });
        })
    }
    mediaPopup.open();
}

/**
 * Insert leading zero to make 2 digits
 * @param {*} g Digit string
 * @returns Two-digit string
 */
function d2(g) {
    return g ? ("0" + g.trim()).slice(-2) : "00";
}

function normalDate(yd, m, dy) {
    if (!dy || !m || !yd) return "";
    // Assume month is in the middle - not USA
    const dtString = (yd > 2000 ? [yd, d2(m), d2(dy)] : [dy, d2(m), d2(yd)]).join('-');
    const date = new Date(dtString);
    try {
        return date ? date.toISOString().substring(0, 10) : "";
    } catch {
        return ""; // Illegal month or day like 2001-09-31
    }
}

/**
 * Parse two dates, a time, and a comment.
 * Dates are 3 groups of digits, month in middle, separated by [-/:.]
 * Time is 2 groups of digits separated by [-/:.]
 * @param {String} s : [Date1] [Date2] [time] [text] 
 * @returns [Date1 time, Date2, text]
 */
function readDates(s) {
    const dg = "([0-9]+)[-\/:.]+";
    const dge = "([0-9]+)";
    const ddgg = `^(?:${dg}${dg}${dge})(?:[-\/ :.]+${dg}${dg}${dge})?(.*)`;
    const re = new RegExp(ddgg);
    const c = (s.replace(/^[\s-]+/, "") + " ").match(re);
    if (!c) return ["", "", s];
    let dg1 = normalDate(c[1], c[2], c[3]);
    let dg2 = normalDate(c[4], c[5], c[6]);
    if (!dg1) dg1 = new Date().toISOString().slice(0, 10);
    if (dg1 && (!dg2 || !(dg1.localeCompare(dg2) < 0))) dg2 = dg1;
    let remainder = c[7].trim();
    let r = remainder.match(new RegExp(`^[- ]*(?:${dg}${dge})?(.*)`));
    if (r && r[1]) {
        dg1 += ` ${d2(r[1])}:${d2(r[2])}`;
        remainder = r[3].trim();
    }
    return [dg1, dg2, remainder];
}

function parseFN(fn) {
    let dtstart = dtend = dtinfo = "";
    let title = "" + Date.now();


    const dateAndInfo = fn.match(/[0-9][- 0-9]{9}.*?$/);
    if (!dateAndInfo) {
        title = fn;
    } else {
        [dtstart, dtend, dtinfo] = readDates(dateAndInfo[0]);
        dtinfo = dtinfo.replace("¬", "|");
        if (dateAndInfo.index == 0) {
            title = dtinfo;
            dtinfo = "";
        } else {
            title = fn.substring(0, dateAndInfo.index);
        }
    }
    title = title.replace("¬", "|").trim();

    return [title, dtstart, dtend, dtinfo];
}

function getGigData(gig) {
    if (!gigio(".giglist").classList.contains("editing")) return false;
    const postid = gig.attributes["data-id"]?.value;
    const titleDiv = gig.querySelector(".gig-title");
    let title = titleDiv.innerText;
    // sanitize
    titleDiv.innerText = title;
    title = titleDiv.innerHTML;
    let dtinfo = inputValue(gig, ".gig-dtinfo").replaceAll('&', "&amp;").replaceAll('<', "&lt;").replaceAll('>', '&gt;');
    let gigPic = gig.querySelector(".gigpic .full").attributes["src"]?.value || "";
    const dtstart = inputValue(gig, ".gig-dtstart");
    const dtend = inputValue(gig, ".gig-dtend");

    let recursfortnight = gig.querySelector(".gig-r14d").checked;

    let recursday = inputValue(gig, ".gig-recursday");

    let recursweeks = "";
    gig.querySelectorAll(".gig-recursweek input").forEach((cb) => {
        if (cb.checked) {
            let id = cb.id.substr(-1);
            recursweeks += id;
        }
    });
    let venue = inputValue(gig, ".gig-venue");
    let booklabel = inputValue(gig, ".gig-booklabel");
    let bookinglink = inputValue(gig, ".gig-bookinglink");
    let locallink = gig.querySelector(".gig-local-link")?.checked;
    let link = gig.querySelector(".gig-content")?.dataset?.link || "";
    let content = gig.querySelector(".gig-content-full")?.textContent || "";

    return {
        id: postid,
        title: title,
        pic: gigPic,
        link: link,
        content: content,
        meta: {
            dtstart: dtstart,
            dtend: dtend || dtstart,
            dtinfo: dtinfo,
            recursfortnight: recursfortnight,
            recursday: recursday,
            recursweeks: recursweeks,
            venue: venue,
            booklabel: booklabel,
            bookinglink: bookinglink,
            locallink: locallink
        }
    };
};

function validate(data, savedData) {
    if (data.meta?.dtstart && (data.meta.dtstart?.localeCompare(data.meta?.dtend || "") || 0) > 0) {
        // dtend < dtstart
        const diff = new Date(savedData?.meta?.dtend || 0) - new Date(savedData?.meta?.dtstart || 0);
        const fixedDtend = new Date(data.meta.dtstart).valueOf() + Math.max(0, diff);
        data.meta.dtend = new Date(fixedDtend).toISOString().substring(0, 10);
    }
    if (data.meta?.recursfortnight) {
        data.meta.recursweeks = "";
    }
    if (!data.meta?.recursday) {
        data.meta.recursweeks = "";
    }
    if (!data.meta.recursweeks?.trim() && !data.meta.recursfortnight) {
        data.meta.recursday = 0;
    }
}

function dateString(d) {
    date.toISOString().substring(0, 10);
}

function parentOfClass(e, ofClass) {
    if (!e) return null;
    if (e.classList.contains(ofClass)) return e;
    return parentOfClass(e.parentElement, ofClass);
}

/**
 * Set event listeners for one or many 
 * @param {array|Gig} gigs 
 */
function setHandlers(gigs) {
    (gigs.forEach ? gigs : [gigs]).forEach(gig => {
        gigUpdateHandlers.forEach(f => f(gig));
    })
}
/**
 * Current value of an <input>
 * @param {*} gigElement 
 * @param {*} inputFieldSelector 
 * @returns 
 */
function inputValue(gigElement, inputFieldSelector) {
    return gigElement.querySelector(inputFieldSelector)?.value || "";
}

/**
 * Make the end date look less significant if it's the same as start date
 * @param {Element|Array(Element)} gig
 */
function setGigFormColours(g) {
    let dtstart = inputValue(g, "input.gig-dtstart");
    let dtend = inputValue(g, "input.gig-dtend");
    let recursday = inputValue(g, ".gig-recursday");
    let recursdayValue = 1 * recursday || 0;
    let recursfortnight = g.querySelector(".gig-r14d").checked;
    let rweekscount = g.querySelectorAll(".gig-recursweek input:checked").length;
    g.classList.toggle("onedate", dtstart == dtend);
    g.classList.toggle("recurs", recursfortnight || rweekscount > 0);

    if ((recursdayValue == 0 && rweekscount > 0) || recursfortnight) {
        g.querySelector(".gig-recursday").value = new Date(dtstart).getDay();
    }

    let locallink = g.querySelector(".gig-local-link").checked;
    g.classList.toggle("locallink", !!locallink);
};

gigUpdateHandlers.push(gig => {
    // Update end date with start date unless they're different
    gig.addEventListener("input", function (e) {
        if (e.target.classList.contains("gig-dtstart")) {
            let saved = JSON.parse(gig.savedData || "");
            let dtend = gig.querySelector(".gig-dtend");
            if (saved && saved.meta.dtend == saved.meta.dtstart ||
                dtend.value.localeCompare(e.target.value) < 0
            ) {
                dtend.value = e.target.value;
            }
        }
        if (e.target.classList.contains("gig-r14d")) {
            if (e.target.checked) {
                gig.querySelectorAll(".gig-recursweek input[type='checkbox']").forEach((cb => cb.checked = false));
            }
        }
        if (e.target.classList.contains("gig-recursday")) {
            if (1 * e.target.value) {
                const weekBoxes = gig.querySelectorAll(".gig-recursweek input[type='checkbox']");
                if (!gig.querySelector(".gig-r14d").checked && Array.from(weekBoxes).every(cb => cb.checked == false)) {
                    weekBoxes.forEach((cb => cb.checked = true));
                }
            } else {
                gig.querySelector(".gig-r14d").checked = false;
                gig.querySelectorAll(".gig-recursweek input[type='checkbox']").forEach((cb => cb.checked = false));
            }
        }
        let rweekscount = gig.querySelectorAll(".gig-recursweek input:checked").length;
        if (rweekscount > 0) {
            gig.querySelector(".gig-r14d").checked = false;
        }
        setGigFormColours(gig);
    });
    setGigFormColours(gig);
});

gigUpdateHandlers.push(gig => {
    gig.addEventListener("focusout", function (e) {
        // https://learn.wordpress.org/tutorial/interacting-with-the-wordpress-rest-api/
        if (!gigio(".giglist").classList.contains("editing")) return;
        gig.focussed = setTimeout(() => {
            // Do this when it's clear we're not just 
            // hopping to another field in same gig
            const data = getGigData(gig);
            if (data && gig.savedData != JSON.stringify(data)) {
                validate(data, gig.savedData ? JSON.parse(gig.savedData) : "");
                //console.log("change " + JSON.stringify(data));
                // Exclude content from save - it's only edited in WP post editor
                const { content, ...saveData } = data;
                const post = new wp.api.models.Post(saveData);
                threadFlag(1);
                post.save().done(post => { // ?? doesn't work with await?
                    threadFlag(-1);
                    refreshGig(gig, data);
                });
            }
        }, 100);
    });

    gig.addEventListener("focusin", function (e, a) {
        if (gig.focussed) {
            // Just been in another field in same gig
            clearTimeout(gig.focussed);
            gig.focussed = null;
        }
        gig.savedData = JSON.stringify(getGigData(gig));
    });
});


/**
 * Apply a map of replacements to the template HTML for a gig
 * @param {*} post 
 * @param {*} map 
 */
function gigTemplateEditingMap(post, map) {
    let gigdayoptions = ["-", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map(
        (day, i) =>
            `<option value=${i} ${i == post.meta.recursday ? " selected" : ""}>${day}</option>`
    ).join("");
    let recursweeks = ("" + post.meta.recursweeks) || "";
    let gigweekoptions =
        [1, 2, 3, 4, 5].map((i) => {
            let id = `gig-rw-${post.id}-${i}`;
            return `<span ${i == 5 ? "title='4th or 5th'" : ""}><input type='checkbox' id='${id}' name='${id}' 
            ${(recursweeks.indexOf("" + i) < 0 ? "" : " checked")}/>
            <label for="${id}" >${i == 5 ? "last" : i}</label></span>`;
        }).join("");
    let gigfortnightoption = `<input class='gig-r14d' type='checkbox' id='gig-r14d-${post.id}' name='gig-r14d-${post.id}' ${post.meta.recursfortnight ? " checked" : ""} />`;

    map["gigdtstart"] = post.meta.dtstart || "";
    map["gigdtype"] = post.meta.dtstart.length > 10 ? "datetime-local" : "date";
    map["gigdtend"] = (post.meta.dtend || "").substring(0, 10);
    map["gigdayoptions"] = gigdayoptions;
    map["gigweekoptions"] = gigweekoptions;
    map["gigfortnightoption"] = gigfortnightoption;
    map["venue"] = post.meta.venue || "";
    map["booklabel"] = post.meta.booklabel || "";
    map["bookinglink"] = post.meta.bookinglink || "";
    map["locallink"] = post.meta.locallink ? "checked" : "";
    map["gigeditlink"] = `${userSettings?.url || ""}/wp-admin/post.php?post=${post.id}&action=edit`.replace("//", "/");
}

/**
 * User clicked Delete on a gig
 * @param {} id 
 */
function deleteGig(id) {
    let gig = gigio(`.gig[data-id="${id}"]`);
    let title = gig.querySelector(".gig-title").innerText;
    if (confirm(`Delete event "${title}" ?`)) {
        const post = new wp.api.models.Post({ id: id });
        threadFlag(1);
        post.destroy().done(function (post) {
            gigio(`.gig[data-id="${id}"]`).remove();
            threadFlag(-1);
            if (window.gigsElementsInOrder) {
                // align-columns layout
                window.location.reload();
            }
        })
    }
}

/**
 * Admin chose a 'show from' date
 * @param {Date} d 
 */
function setFromDate(d) {
    const locWithoutOldDate = location.href.replace(/[?&]asif=[^?&]+/g, "").replace(/\?/, "&");
    const newUrl = (!d || d == new Date().toISOString().substring(0, 10))
        ? locWithoutOldDate
        : locWithoutOldDate + "&asif=" + d;
    window.open(newUrl.replace(/&/, "?"), "_self");
    threadFlag(1);
}

/**
 * Admin chose an alignment option
 * @param {*} alignment 
 */
function setAlignment(alignment) {
    const locWithoutOldAlign = location.href.replace(/[?&]align=[^&?]+/g, "").replace(/\?/, "&");
    let newUrl = locWithoutOldAlign + (alignment ? "&align=" + alignment : "");
    window.open(newUrl.replace(/&/, "?"), "_self");
    threadFlag(1);
}

const locWithoutAlign = location.href.replace(/\?.*/, "");
if (locWithoutAlign != location.href) {
    window.history.replaceState({}, "", locWithoutAlign);
}

/**
 * Open WordPress post editor for a gig
 * @param {HTMLElement} element - The .gig-content element
 * @param {Event} event - Click event
 */
function openGigEditor(element, event) {
    const editLink = element.dataset.editlink;
    if (editLink) {
        window.open(editLink, "_blank");
        // Refresh on return to this window
        window.addEventListener("focus", function (event) {
            window.location.reload();
            return false;
        }, false);
    }
}
