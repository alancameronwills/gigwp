

// ****** Nevern Expanding Images **********

function nevernExpandImg(src) {
    //window.open(src, "expandedpix");
    if (src) {
        jQuery("#nevernBigPicImg")[0].src = src;
        jQuery("#nevernBigPic").show();
    } else {
        jQuery('#nevernBigPic').hide();
        jQuery("#nevernBigPicImg")[0].src = "";
    }
}

function setXIhandler(jq) {
    jq.click(function () {
        let img = jQuery(this)[0];
        nevernExpandImg(img.src);
    }).css("cursor", "pointer");
}

function nevernExpandImages() {
    let html = `<div ` +
        `style="position:fixed;top:0;left:0;height:100%;width:100%;background-color:black;z-index:99999;cursor:pointer;display:none;" ` +
        `id="nevernBigPic" onclick="nevernExpandImg('')" onkeydown="nevernExpandImg('')">` +
        `<img id="nevernBigPicImg" alt="image expanded to fill screen - ESC to collapse"` +
        ` style="height:100%;width:100%;object-fit:contain;"  onkeydown="nevernExpandImg('')" src=""/></div>`;
    jQuery(document.body).append(html);

    jQuery("body").keydown(event => {
        if (event.keyCode === 27) nevernExpandImg();
    });

    setXIhandler(jQuery(".gig img"));

    if (window.gigUpdateHandlers) {
        gigUpdateHandlers.push(jqgig => {
            setXIhandler(jqgig.find("img"));
        });
    }

}

// ********* Display gigs ************

/**
 * On loading the page, show the content
 * @param {json} gigListJson 
 */
function fillGigList(gigListJson) {
    const gigList = JSON.parse(gigListJson);
    let gigListHtml = gigList.map(gig => gigHtml(gig)).join("\n");
    jQuery(".giglist>.gigs").html(gigListHtml);
    if (window?.setHandlers) setHandlers(jQuery(".gig"));
}


function gigHtml(post) {

    let imgLink = post.thumbnail_image || post.pic || (post.link || "").replace(/p=[0-9]+/, "p=" + post.featured_media);
    let gigdates = friendlyDate(post.meta.dtstart) +
        (post.meta.dtstart == post.meta.dtend ? "" : " - " + friendlyDate(post.meta.dtend));

    let template = jQuery("#gigtemplate").html();
    let maps = {
        "gigid": post.id,
        "gigtitle": post.title?.rendered || post.title,
        "gigpic": imgLink,
        "gigdates": gigdates,
        "gigdtinfo": post.meta.dtinfo || ""
    };

    if (window.gigTemplateEditingMap) {
        gigTemplateEditingMap(post, maps);
    }

    let show = template;
    Object.getOwnPropertyNames(maps).forEach(v => {
        show = show.replaceAll(`%${v}`, maps[v]);
    });
    return show;
}

function friendlyDate(d = "") {
    if (!d) return "";
    return new Date(d).toLocaleDateString();
}

