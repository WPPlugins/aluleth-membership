/* Aluleth Membership – block editor: "Member area" and "Join card" blocks, "Who sees this block" on every block. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var data = window.alulethMembershipBlocks || { levels: [] };
	var SSR = wp.serverSideRender;

	function levelChecks( value, onChange ) {
		if ( ! data.levels.length ) {
			return el( 'p', {}, __( 'Create a membership level first.', 'aluleth-membership' ) );
		}
		return data.levels.map( function ( level ) {
			var on = ( value || [] ).indexOf( level.id ) !== -1;
			return el( c.CheckboxControl, {
				key: level.id,
				label: level.name,
				checked: on,
				onChange: function ( checked ) {
					var next = ( value || [] ).filter( function ( id ) {
						return id !== level.id;
					} );
					if ( checked ) {
						next.push( level.id );
					}
					onChange( next );
				},
				__nextHasNoMarginBottom: true,
			} );
		} );
	}

	// "Who sees this block".
	wp.hooks.addFilter( 'blocks.registerBlockType', 'aluleth-membership/attribute', function ( settings ) {
		settings.attributes = Object.assign( {}, settings.attributes, { alulethMembers: { type: 'object' } } );
		return settings;
	} );

	var withPanel = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var rule = props.attributes.alulethMembers || {};
			var show = rule.show || 'all';
			var set = function ( next ) {
				var merged = Object.assign( {}, rule, next );
				props.setAttributes( { alulethMembers: 'all' === merged.show ? undefined : merged } );
			};
			return el(
				wp.element.Fragment,
				{},
				el( BlockEdit, props ),
				props.isSelected && el(
					be.InspectorControls,
					{},
					el(
						c.PanelBody,
						{ title: __( 'Membership', 'aluleth-membership' ), initialOpen: 'all' !== show },
						el( c.SelectControl, {
							label: __( 'Who sees this block', 'aluleth-membership' ),
							value: show,
							options: [
								{ value: 'all', label: __( 'Everyone', 'aluleth-membership' ) },
								{ value: 'members', label: __( 'Members', 'aluleth-membership' ) },
								{ value: 'guests', label: __( 'Everyone but members', 'aluleth-membership' ) },
							],
							onChange: function ( v ) {
								set( { show: v } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						'all' !== show && el( 'p', { className: 'components-base-control__help' }, __( 'Levels (none ticked: any level):', 'aluleth-membership' ) ),
						'all' !== show && levelChecks( rule.levels, function ( v ) {
							set( { levels: v } );
						} )
					)
				)
			);
		};
	}, 'withAlulethMembers' );
	wp.hooks.addFilter( 'editor.BlockEdit', 'aluleth-membership/panel', withPanel );

	// Member area block.
	wp.blocks.registerBlockType( 'aluleth-membership/member-area', {
		apiVersion: 3,
		title: __( 'Member area', 'aluleth-membership' ),
		icon: 'id-alt',
		category: 'widgets',
		attributes: {
			content: { type: 'boolean', default: true },
			count: { type: 'number', default: 6 },
		},
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					{},
					el(
						c.PanelBody,
						{ title: __( 'Member area', 'aluleth-membership' ) },
						el( c.ToggleControl, {
							label: __( 'Show "Opened for you"', 'aluleth-membership' ),
							checked: a.content,
							onChange: function ( v ) {
								props.setAttributes( { content: v } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						a.content && el( c.RangeControl, {
							label: __( 'Number of items', 'aluleth-membership' ),
							value: a.count,
							min: 1,
							max: 24,
							onChange: function ( v ) {
								props.setAttributes( { count: v } );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( SSR, { block: 'aluleth-membership/member-area', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );

	// Join card block.
	wp.blocks.registerBlockType( 'aluleth-membership/join-card', {
		apiVersion: 3,
		title: __( 'Join card', 'aluleth-membership' ),
		icon: 'groups',
		category: 'widgets',
		attributes: {
			levels: { type: 'array', default: [], items: { type: 'number' } },
			title: { type: 'string', default: '' },
			text: { type: 'string', default: '' },
			design: { type: 'string', default: '' },
		},
		supports: { html: false, align: [ 'wide' ] },
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					{},
					el(
						c.PanelBody,
						{ title: __( 'Join card', 'aluleth-membership' ) },
						el( 'p', {}, __( 'Levels (none ticked: every level shown on join cards):', 'aluleth-membership' ) ),
						levelChecks( a.levels, function ( v ) {
							props.setAttributes( { levels: v } );
						} ),
						el( c.TextControl, {
							label: __( 'Title', 'aluleth-membership' ),
							value: a.title,
							onChange: function ( v ) {
								props.setAttributes( { title: v } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.TextareaControl, {
							label: __( 'Text', 'aluleth-membership' ),
							value: a.text,
							onChange: function ( v ) {
								props.setAttributes( { text: v } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.SelectControl, {
							label: __( 'Design', 'aluleth-membership' ),
							value: a.design,
							options: [
								{ value: '', label: __( 'As in the settings', 'aluleth-membership' ) },
								{ value: 'clean', label: __( 'Clean', 'aluleth-membership' ) },
								{ value: 'soft', label: __( 'Soft', 'aluleth-membership' ) },
								{ value: 'dark', label: __( 'Dark', 'aluleth-membership' ) },
							],
							onChange: function ( v ) {
								props.setAttributes( { design: v } );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( SSR, { block: 'aluleth-membership/join-card', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
