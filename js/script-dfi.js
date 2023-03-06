/**
 * Script for dynamic featured image plugin.
 *
 * @package dynamic-featured-image
 * @subpackage js
 *
 * Copyright (c) 2013, Ankit Pokhrel <info@ankitpokhrel.com, https://ankitpokhrel.com>
 */
window.addEventListener('load', () => {
    let featuredImages = document.querySelectorAll('.dfiFeaturedImage');

    for (let featuredImage of featuredImages) {
        featuredImage.addEventListener('click', (ev) => {
            ev.preventDefault();
    
            if (featuredImage === null) {
                return;
            }

            let dfiUploader = wp.media({
                title: DFI_SPECIFIC.mediaSelector_title,
                button: {
                    text: DFI_SPECIFIC.mediaSelector_buttonText
                },
                multiple: false,
                library: {
                    type: ['image'],
                }
            }).on('select', () => {
                let attachment = dfiUploader.state().get('selection').first().toJSON();
                let fullSize = attachment.url;
                let imgUrl = (typeof attachment.sizes.thumbnail === 'undefined') ? fullSize : attachment.sizes.thumbnail.url;
                let imgUrlTrimmed = imgUrl.replace(DFI_SPECIFIC.upload_url, "");
                let fullUrlTrimmed = fullSize.replace(DFI_SPECIFIC.upload_url, "");

                let featuredBox = featuredImage.closest('.inside');

                featuredBox.querySelector('.dfiImg').setAttribute('src', imgUrl);
                featuredBox.querySelector('.dfiImg').setAttribute('data-src', fullSize);

                featuredBox.querySelector('.dfiFeaturedImage').classList.add('hasFeaturedImage');

                let dfiFeaturedImages = [imgUrlTrimmed, fullUrlTrimmed];

                let medium = attachment.url;

                if (typeof attachment.sizes.medium !== 'undefined') {
                    medium = attachment.sizes.medium.url;
                }

                featuredBox.querySelector('img').setAttribute('src', medium);
                featuredBox.querySelector('input.dfiImageHolder').value = dfiFeaturedImages;
            });

            dfiUploader.on('open', () => {
                let attached = featuredImage.getAttribute('data-attachment-id');
                let selection = dfiUploader.state().get('selection');

                if (attached) {
                    selection.add(wp.media.attachment(attached));
                }
            });

            dfiUploader.open();

            return false;
        });
    }
});