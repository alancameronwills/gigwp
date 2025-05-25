
function insertGig(post) {
    let imgLink = post.link.replace(/p=[0-9]+/, "p=" + post.featured_media);
    jQuery(`<div class="gig" data-id="${post.id}">
                    <div class="gig-title gig-field">${post.title.rendered}</div>
                    <img src="${imgLink}" class="gigpic"/>
                    <div> 
                        <input class="gig-dtstart gig-field" type="date" value="${post.meta.dtstart}" readonly />
                        -
                        <input class="gig-dtend gig-field" type="date" value="${post.meta.dtend}" readonly />
                    </div>
                `).insertAfter(jQuery(".giglist .controls"))
}

function editGig() {
    if (!jQuery(".giglist").hasClass("editing")) {
        jQuery(".giglist").addClass("editing");
        jQuery("div.gig-field").attr("contentEditable", true);
        jQuery("input.gig-field").attr("disabled", false);
        jQuery("#editButton").text("Done");
    } else {
        jQuery(".giglist").removeClass("editing");
        jQuery(".gig-field").attr("contentEditable", false);
        jQuery("input.gig-field").attr("disabled", true);
        jQuery("#editButton").text("Edit");

    }
}

function addGig() {
    openMediaPopup();
}

function newPost(title, img, dtstart = "", dtend = "", dtinfo="") {
    if (!window.gigiauCategoryId) return;
    threadFlag(1);
    const query = {
        title: title,
        featured_media: img,
        content: title,
        status: 'publish',
        categories: [window.gigiauCategoryId],
        meta: {
            'dtstart': dtstart,
            'dtend': dtend,
            'dtinfo': dtinfo
        }
    };
    const post = new wp.api.models.Post(query);
    post.save().done(post => {
        insertGig(post);
        threadFlag(-1);
    });
}

function copyEditFieldsToShow(gigDiv, data) {
    let gig = jQuery(gigDiv);
    gig.find("show-info").text(data.meta.dtinfo);
    gig.find("show-dtstart").text(new Date(data.meta.dtstart).toString("").substring(0,15));
    gig.find("show-dtend").text(
        data.meta.dtstart == data.meta.dtend ? "" : " - " +
        new Date(data.meta.dtend).toString("").substring(0,15));

}

// ******** Flag work in progress *******

function beforeUnloadHandler(event) {
    event.preventDefault();
    event.returnValue = "Still uploading changes";
    return true;
}

var threads = 0;

function threadFlag(count) {
    threads += count;
    const flag = jQuery(".giglist");
    if (threads > 0) {
        flag.addClass("edit-in-progress");
        // https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event
        window.addEventListener("beforeunload", beforeUnloadHandler);
    } else {
        flag.removeClass("edit-in-progress");
        window.removeEventListener("beforeunload", beforeUnloadHandler);
    }
}

// *****************************

var mediaPopup;

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
    }
    var self = this;
    mediaPopup.on("select", function (...args) {
        let imagepages = this.models[0].attributes.selection.models;
        let images = imagepages.map(ip => ip.attributes);
        // title,url,id,caption,description,date, editLink, height,width,filesizeInBytes,id,link,mime,name,sizes[]
        /*
        let images = self.window.state().get('selection').map(
            img => img.toJSON()
        ); */
        images.forEach(img => {
            let dtstart = dtend = "";
            try {
                if (img.caption) {
                    let dates = img.caption.split("-");
                    dtstart = readDate(dates[0]);
                    dtend = readDate(dates?.[1]) || dtstart;
                    dtinfo = (dates?.[2] || "").trim();
                }
            } catch { }
            newPost(img.title, img.id, dtstart, dtend);

        });
    })
    mediaPopup.open();
}

function readDate(s) {
    if (!s) return "";
    let d = new Date(s);
    if (!d) return "";
    return d.toISOString().substring(0, 10);
}

function getGigData(gigElement) {
    if (!jQuery(".giglist").hasClass("editing")) return false;
    const gig = jQuery(gigElement);
    const postid = gig.attr("data-id");
    const titleDiv = gig.find(".gig-title");
    let title = titleDiv.text();
    // sanitize
    title = titleDiv.text(title).html();
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

    return {
        id: postid,
        title: title,
        meta: {
            dtstart: dtstart,
            dtend: dtend || dtstart,
            recursday : recursday,
            recursweeks: recursweeks
        }
    };
};


jQuery(() => {
    jQuery(".gig").on("focusout", function (e, a) {
        // https://learn.wordpress.org/tutorial/interacting-with-the-wordpress-rest-api/
        if (!jQuery(".giglist").hasClass("editing")) return;
        const data = getGigData(this);
        if (data && this.savedData != JSON.stringify(data)) {
            this.focussed = setTimeout(() => {
                // Do this when it's clear we're not just 
                // hopping to another field in same gig
                const post = new wp.api.models.Post(data);
                threadFlag(1);
                post.save().done(post => { // ?? doesn't work with await?
                    threadFlag(-1);
                    copyEditFieldsToShow(this, data);
                });
            }, 50);
        }
    });
    jQuery(".gig").on("focusin", function (e, a) {
        if (this.focussed) {
            // Just been in another field in same gig
            clearTimeout(this.focussed);
            this.focussed = null;
        }
        this.savedData = JSON.stringify(getGigData(this));
    })
});
