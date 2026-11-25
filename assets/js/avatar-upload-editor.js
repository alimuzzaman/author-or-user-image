/* global wp */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;

	blocks.registerBlockType('author-or-user-image/avatar-upload', {
		apiVersion: 3,
		title: i18n.__('Avatar upload', 'author-or-user-image'),
		description: i18n.__('Let signed-in visitors upload or remove their own avatar.', 'author-or-user-image'),
		category: 'widgets',
		icon: 'admin-users',
		supports: { html: false },
		attributes: {
			showPreview: { type: 'boolean', default: true },
			previewSize: { type: 'integer', default: 0 },
			alignment: { type: 'string', default: '' },
			uploadLabel: { type: 'string', default: '' },
			removeLabel: { type: 'string', default: '' },
			loginLabel: { type: 'string', default: '' }
		},
		edit: function (props) {
			var attrs = props.attributes || {};
			var showPreview = attrs.showPreview !== false;
			var previewSize = typeof attrs.previewSize === 'number' ? attrs.previewSize : 0;
			var alignment = typeof attrs.alignment === 'string' ? attrs.alignment : '';
			var uploadLabel = typeof attrs.uploadLabel === 'string' ? attrs.uploadLabel : '';
			var removeLabel = typeof attrs.removeLabel === 'string' ? attrs.removeLabel : '';
			var loginLabel = typeof attrs.loginLabel === 'string' ? attrs.loginLabel : '';

			var summary = [];
			if (!showPreview) {
				summary.push(i18n.__('Preview hidden', 'author-or-user-image'));
			} else if (previewSize > 0) {
				summary.push(i18n.__('Preview size:', 'author-or-user-image') + ' ' + previewSize + 'px');
			}
			if (alignment) {
				summary.push(i18n.__('Alignment:', 'author-or-user-image') + ' ' + alignment);
			}
			if (uploadLabel) {
				summary.push(i18n.__('Upload label:', 'author-or-user-image') + ' ' + uploadLabel);
			}
			if (removeLabel) {
				summary.push(i18n.__('Remove label:', 'author-or-user-image') + ' ' + removeLabel);
			}

			var className = 'zmai-front-end' + (alignment ? ' zmai-align-' + alignment : '');

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: i18n.__('Display & labels', 'author-or-user-image'), initialOpen: true },
						el(ToggleControl, {
							label: i18n.__('Show current avatar preview', 'author-or-user-image'),
							checked: showPreview,
							onChange: function (value) {
								props.setAttributes({ showPreview: !!value });
							}
						}),
						el(RangeControl, {
							label: i18n.__('Preview size (px, 0 = site default)', 'author-or-user-image'),
							value: previewSize,
							min: 0,
							max: 512,
							step: 8,
							onChange: function (value) {
								props.setAttributes({ previewSize: typeof value === 'number' ? value : 0 });
							}
						}),
						el(SelectControl, {
							label: i18n.__('Alignment', 'author-or-user-image'),
							value: alignment,
							options: [
								{ label: i18n.__('Default', 'author-or-user-image'), value: '' },
								{ label: i18n.__('Left', 'author-or-user-image'), value: 'left' },
								{ label: i18n.__('Center', 'author-or-user-image'), value: 'center' },
								{ label: i18n.__('Right', 'author-or-user-image'), value: 'right' }
							],
							onChange: function (value) {
								props.setAttributes({ alignment: value || '' });
							}
						}),
						el(TextControl, {
							label: i18n.__('Upload button label', 'author-or-user-image'),
							placeholder: i18n.__('Upload image', 'author-or-user-image'),
							value: uploadLabel,
							onChange: function (value) {
								props.setAttributes({ uploadLabel: value });
							}
						}),
						el(TextControl, {
							label: i18n.__('Remove button label', 'author-or-user-image'),
							placeholder: i18n.__('Remove image', 'author-or-user-image'),
							value: removeLabel,
							onChange: function (value) {
								props.setAttributes({ removeLabel: value });
							}
						}),
						el(TextControl, {
							label: i18n.__('Logged-out link text', 'author-or-user-image'),
							placeholder: i18n.__('Log in to manage your avatar.', 'author-or-user-image'),
							value: loginLabel,
							onChange: function (value) {
								props.setAttributes({ loginLabel: value });
							}
						})
					)
				),
				el(
					'div',
					blockEditor.useBlockProps({ className: className }),
					el('strong', null, i18n.__('Avatar upload', 'author-or-user-image')),
					el('p', null, i18n.__('On the published page, signed-in visitors can manage their own avatar. Other visitors see a login link.', 'author-or-user-image')),
					summary.length ? el('p', { className: 'description' }, summary.join(' · ')) : null
				)
			);
		},
		save: function () { return null; }
	});
})(wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n);
