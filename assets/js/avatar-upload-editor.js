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

	function cleanLabel(value, maxLen) {
		if (typeof value !== 'string') {
			return '';
		}
		var cleaned = value.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
		return cleaned ? cleaned.slice(0, maxLen) : '';
	}

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
			var effectivePreviewSize = previewSize > 0 ? Math.min(512, Math.max(32, previewSize)) : 0;
			var rawAlignment = typeof attrs.alignment === 'string' ? attrs.alignment : '';
			var alignment = rawAlignment === 'left' || rawAlignment === 'center' || rawAlignment === 'right' ? rawAlignment : '';
			var uploadLabel = typeof attrs.uploadLabel === 'string' ? attrs.uploadLabel : '';
			var removeLabel = typeof attrs.removeLabel === 'string' ? attrs.removeLabel : '';
			var loginLabel = typeof attrs.loginLabel === 'string' ? attrs.loginLabel : '';
			var summaryUpload = cleanLabel(uploadLabel, 80);
			var summaryRemove = cleanLabel(removeLabel, 80);

			var summary = [];
			if (!showPreview) {
				summary.push(i18n.__('Preview hidden', 'author-or-user-image'));
			} else if (effectivePreviewSize > 0) {
				summary.push(i18n.__('Preview size:', 'author-or-user-image') + ' ' + effectivePreviewSize + 'px');
			}
			if (alignment) {
				summary.push(i18n.__('Alignment:', 'author-or-user-image') + ' ' + alignment);
			}
			if (summaryUpload) {
				summary.push(i18n.__('Upload label:', 'author-or-user-image') + ' ' + summaryUpload);
			}
			if (summaryRemove) {
				summary.push(i18n.__('Remove label:', 'author-or-user-image') + ' ' + summaryRemove);
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
							value: effectivePreviewSize,
							min: 0,
							max: 512,
							step: 8,
							onChange: function (value) {
								var next = typeof value === 'number' && !isNaN(value) ? Math.round(value) : 0;
								if (next > 0 && next < 32) {
									next = effectivePreviewSize >= 32 ? 0 : 32;
								} else if (next < 0) {
									next = 0;
								} else if (next > 512) {
									next = 512;
								}
								props.setAttributes({ previewSize: next });
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
							maxLength: 80,
							value: uploadLabel,
							onChange: function (value) {
								props.setAttributes({ uploadLabel: value });
							}
						}),
						el(TextControl, {
							label: i18n.__('Remove button label', 'author-or-user-image'),
							placeholder: i18n.__('Remove image', 'author-or-user-image'),
							maxLength: 80,
							value: removeLabel,
							onChange: function (value) {
								props.setAttributes({ removeLabel: value });
							}
						}),
						el(TextControl, {
							label: i18n.__('Logged-out link text', 'author-or-user-image'),
							placeholder: i18n.__('Log in to manage your avatar.', 'author-or-user-image'),
							maxLength: 120,
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
