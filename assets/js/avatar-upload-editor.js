/* global wp */
(function (blocks, element, blockEditor, i18n) {
	'use strict';
	blocks.registerBlockType('author-or-user-image/avatar-upload', {
		apiVersion: 3,
		title: i18n.__('Avatar upload', 'author-or-user-image'),
		description: i18n.__('Let signed-in visitors upload or remove their own avatar.', 'author-or-user-image'),
		category: 'widgets',
		icon: 'admin-users',
		supports: { html: false },
		edit: function () {
			return element.createElement('div', blockEditor.useBlockProps({ className: 'zmai-front-end' }),
				element.createElement('strong', null, i18n.__('Avatar upload', 'author-or-user-image')),
				element.createElement('p', null, i18n.__('On the published page, signed-in visitors can manage their own avatar. Other visitors see a login link.', 'author-or-user-image')));
		},
		save: function () { return null; }
	});
})(wp.blocks, wp.element, wp.blockEditor, wp.i18n);
