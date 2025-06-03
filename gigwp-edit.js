var gigUpdateHandlers = [];


// ************* Editing gigs ***********

/**
 * Show new post on page without reloading page
 * @param {} post 
 */
function insertGig(post) {
    let jqShow = jQuery(gigHtml(post));
    jQuery(".giglist>.gigs").prepend(jqShow);
    setHandlers(jqShow);
    setFieldsEditable();
    return jqShow;
}

/**
 * Redisplay after editing
 * @param {} post 
 */
function refreshGig(jqGig, post) {
    let jqShow = jQuery(gigHtml(post));
    jqGig.replaceWith(jqShow);
    setHandlers(jqShow);
    setFieldsEditable();
    return jqShow;
}

/**
 * Edit button clicked. Enable input fields.
 */
function editGig(event, on=null) {
    event?.stopPropagation?.();
    event?.preventDefault?.();
    jQuery(".giglist").toggleClass("editing", on);
    setFieldsEditable();
}
function setFieldsEditable() {
    if (jQuery(".giglist").hasClass("editing")) {
        jQuery("div.gig-field").attr("contentEditable", true);
        jQuery("input.gig-field").attr("disabled", false);
        jQuery("#editButton").text("Done");
    } else {
        jQuery(".gig-field").attr("contentEditable", false);
        jQuery("input.gig-field").attr("disabled", true);
        jQuery("#editButton").text("Edit");
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
 * @param {YYY-MM-DD} dtstart 
 * @param {YYY-MM-DD} dtend 
 * @param {string} dtinfo 
 * @returns void
 */
function newPost(title, img, dtstart = "", dtend = "", dtinfo = "") {
    if (!window.gigiauCategoryId) return;
    threadFlag(1);
    const today = new Date().toISOString().substring(0, 10);
    if (!dtstart || today.localeCompare(dtstart) > 0) {
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
            'dtinfo': dtinfo,
            "recursday": 0
        }
    };
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
                        scrollTop: jQuery("#gig-top").offset().top
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
    const flag = jQuery(".giglist");
    if (threads > 0) {
        flag.addClass("edit-in-progress");
        // https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event
        window.addEventListener("beforeunload", beforeUnloadHandler);
    } else {
        flag.removeClass("edit-in-progress");
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
                        // Or in the filename, optionally with hyphens instead of spaces
                        const fileNameParts = img.title.match(/^(.*?)[ -]([- 0-9]{10}.*?)$/);
                        if (fileNameParts) {
                            title = (fileNameParts[1] || "").replaceAll("-", " ");
                            [dtstart, dtend, dtinfo] = readDates(fileNameParts[2]);
                            dtinfo = dtinfo.replaceAll("-", " ");
                        } else {
                            title = img.title;
                        }
                    }
                } catch (e) {
                    console.log("Reading dates from caption: ", e);
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
    return ("0" + g.trim()).slice(-2);
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
 * Parse two dates and a comment.
 * Dates are 3 groups of digits, month in middle, no time, separated by [-/:. ]
 * @param {String} s : [Date1] [Date2] [text] 
 * @returns [Date1, Date2, text]
 */
function readDates(s) {
    const dg = "([0-9]+)[-\/ :.]+";
    const ddgg = `^(?:${dg}${dg}${dg})?(?:${dg}${dg}${dg})?(.*)`;
    const re = new RegExp(ddgg);
    const c = (s.replace(/^[\s-]+/, "") + " ").match(re);
    let dg1 = normalDate(c[1], c[2], c[3]);
    let dg2 = normalDate(c[4], c[5], c[6]);
    if (!dg1) dg1 = new Date().toISOString().slice(0, 10);
    if (dg1 && (!dg2 || dg1.localeCompare(dg2) > 0)) dg2 = dg1;
    return [dg1, dg2, c[7].trim()];
}

function getGigData(gigElement) {
    if (!jQuery(".giglist").hasClass("editing")) return false;
    const gig = jQuery(gigElement);
    const postid = gig.attr("data-id");
    const titleDiv = gig.find(".gig-title");
    let title = titleDiv.text();
    // sanitize
    title = titleDiv.text(title).html();
    let dtinfoDiv = gig.find(".gig-dtinfo");
    let dtinfo = dtinfoDiv.val().replaceAll('&', "&amp;").replaceAll('<', "&lt;").replaceAll('>', '&gt;');
    let gigPic = gig.find(".gigpic").attr("src");
    const dtstart = gig.find(".gig-dtstart").val();
    const dtend = gig.find(".gig-dtend").val();

    let recursday = gig.find(".gig-recursday").val();

    let recursweeks = "";
    gig.find(".gig-recursweek input").toArray().forEach((cb) => {
        if (cb.checked) {
            let id = cb.id.substr(-1);
            recursweeks += id;
        }
    });
    let venue = gig.find(".gig-venue").val();
    let booklabel = gig.find(".gig-booklabel").val();
    let bookinglink = gig.find(".gig-bookinglink").val();
    let locallink = gig.find(".gig-local-link").prop("checked");

    return {
        id: postid,
        title: title,
        pic: gigPic,
        meta: {
            dtstart: dtstart,
            dtend: dtend || dtstart,
            dtinfo: dtinfo,
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
    if (!data.meta?.recursday) {
        data.meta.recursweeks = "";
    }
    if (!data.meta.recursweeks?.trim()) {
        data.meta.recursday = 0;
    }
}

function dateString(d) {
    date.toISOString().substring(0, 10);
}

function setHandlers(jqGig) {
    jqGig.on("focusout", function (e, a) {
        // https://learn.wordpress.org/tutorial/interacting-with-the-wordpress-rest-api/
        if (!jQuery(".giglist").hasClass("editing")) return;
        this.focussed = setTimeout(() => {
            // Do this when it's clear we're not just 
            // hopping to another field in same gig
            const data = getGigData(this);
            if (data && this.savedData != JSON.stringify(data)) {
                validate(data, this.savedData ? JSON.parse(this.savedData) : "");
                //console.log("change " + JSON.stringify(data));
                const post = new wp.api.models.Post(data);
                threadFlag(1);
                post.save().done(post => { // ?? doesn't work with await?
                    threadFlag(-1);
                    refreshGig(jQuery(this), data);
                });
            }
        }, 100);
    });

    jqGig.on("focusin", function (e, a) {
        if (this.focussed) {
            // Just been in another field in same gig
            clearTimeout(this.focussed);
            this.focussed = null;
        }
        this.savedData = JSON.stringify(getGigData(this));
    });


    gigUpdateHandlers.forEach(f => f(jqGig));
}

/**
 * Make the end date look less significant if it's the same as start date
 * @param {jQuery} jqGig 
 */

function setEndDateColour(jqGig) {
    jqGig.each((i, g) => {
        const jqg = jQuery(g);
        let dtstart = jqg.find("input.gig-dtstart").val();
        let dtend = jqg.find("input.gig-dtend").val();
        let jqrecursday = jqg.find(".gig-recursday");
        let recursdayValue = 1 * jqrecursday.val() || 0;
        jqg.toggleClass("onedate", dtstart == dtend);
        jqg.toggleClass("recurs", !!recursdayValue);
        let rweekscount = jqg.find(".gig-recursweek input:checked").length;

        if (recursdayValue == 0 && rweekscount > 0) {
            jqrecursday.val(new Date(dtstart).getDay());
        }

        let locallink = jqg.find(".gig-local-link").prop("checked");
        console.log("Locallink:" + locallink);
        jqg.toggleClass("locallink", !!locallink);

    })
}

gigUpdateHandlers.push(jqGig => {
    // Update end date with start date unless they're different
    jqGig.on("input", ".gig-dtstart", function (e, a) {
        let gig = e.delegateTarget;
        let saved = JSON.parse(gig.savedData || "");
        let dtend = jQuery(gig).find(".gig-dtend");
        if (saved && saved.meta.dtend == saved.meta.dtstart ||
            dtend.val().localeCompare(e.target.value) < 0
        ) {
            dtend.val(e.target.value);
        }
        setEndDateColour(jQuery(gig));
    });


    jqGig.on("input", ".gig-dtend", function (e, a) {
        setEndDateColour(jQuery(e.delegateTarget));
    });

})

gigUpdateHandlers.push(jqGig => {
    jqGig.each((i, g) => setEndDateColour(jQuery(g)));
})


gigUpdateHandlers.push(jqGigs => {
    jqGigs.on("input", ".gig-recursday", function (e) {
        const jqGig = jQuery(e.delegateTarget);
        if (1 * e.target.value) {
            jqGig.find(".gig-recursweek input[type='checkbox']").each((i, cb) => jQuery(cb).prop("checked", true));
        } else {
            jqGig.find(".gig-recursweek input[type='checkbox']").each((i, cb) => jQuery(cb).prop("checked", false));
        }
        setEndDateColour(jQuery(e.delegateTarget));
    })
    jqGigs.on("input", "input[type=checkbox]", function (e) {
        setEndDateColour(jQuery(e.delegateTarget));
    })
})

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

    map["gigdtstart"] = post.meta.dtstart || "";
    map["gigdtend"] = post.meta.dtend || "";
    map["gigdayoptions"] = gigdayoptions;
    map["gigweekoptions"] = gigweekoptions;
    map["venue"] = post.meta.venue || "";
    map["booklabel"] = post.meta.booklabel || "";
    map["bookinglink"] = post.meta.bookinglink || "";
    map["locallink"] = post.meta.locallink ? "checked" : "";
}

function deleteGig(id) {
    let gig = jQuery(`.gig[data-id="${id}"]`);
    let title = gig.find(".gig-title").text();
    if (confirm(`Delete event "${title}" ?`)) {
        const post = new wp.api.models.Post({ id: id });
        threadFlag(1);
        post.destroy().done(function (post) {
            jQuery(`.gig[data-id="${id}"]`).remove();
            threadFlag(-1);
        })
    }
}

function setFromDate(d) {
    if (!d || d == new Date().toISOString().substring(0, 10)) {
        window.open(".", "_this");
    } else {
        window.open(".?asif=" + d, "_this");
    }
}