/**
 * Bonnie submissions admin — a DataViews app over the plugin's REST API.
 *
 * Replaces the classic WP_List_Table list screen with the modern @wordpress
 * DataViews component. Filtering, sorting, search and pagination are resolved
 * server-side via the bonnie/v1 REST routes. The free core offers View and a
 * single permanent Delete; add-ons inject further status views and row/bulk
 * actions through the `bonnie.submissions.*` JS filters (see docs/HOOKS.md).
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot, render, useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { addQueryArgs } from '@wordpress/url';
import { applyFilters } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';

const data = window.bonnieData || {};

apiFetch.use( apiFetch.createRootURLMiddleware( data.restUrl ) );
apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	fields: [ 'created_at', 'form', 'from', 'subject', 'status' ],
	filters: [ { field: 'status', operator: 'is', value: 'active' } ],
};

/**
 * Translate a DataViews view into REST query args.
 *
 * @param {Object} view DataViews view state.
 * @return {Object} Query args for the submissions endpoint.
 */
function viewToQuery( view ) {
	const query = {
		page: view.page,
		per_page: view.perPage,
	};

	if ( view.search ) {
		query.search = view.search;
	}

	if ( view.sort && view.sort.field ) {
		query.orderby = view.sort.field;
		query.order = view.sort.direction;
	}

	( view.filters || [] ).forEach( ( filter ) => {
		if ( ! filter.value ) {
			return;
		}
		if ( 'status' === filter.field ) {
			query.status = filter.value;
		}
		if ( 'form' === filter.field ) {
			query.form_id = filter.value;
		}
	} );

	return query;
}

function SubmissionsApp() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ records, setRecords ] = useState( [] );
	const [ pagination, setPagination ] = useState( { totalItems: 0, totalPages: 0 } );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );

	const reload = () => setRefresh( ( value ) => value + 1 );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );

		apiFetch( {
			path: addQueryArgs( '/bonnie/v1/submissions', viewToQuery( view ) ),
		} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				setError( null );
				setRecords( response.items || [] );
				setPagination( {
					totalItems: response.total || 0,
					totalPages: response.totalPages || 0,
				} );
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( err && err.message ? err.message : __( 'Could not load submissions.', 'the-bonnie-situation' ) );
					setRecords( [] );
					setPagination( { totalItems: 0, totalPages: 0 } );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ view, refresh ] );

	const runBatch = ( action, items ) => {
		const ids = items.map( ( item ) => item.id );
		return apiFetch( {
			path: '/bonnie/v1/submissions/batch',
			method: 'POST',
			data: { action, ids },
		} ).then( () => reload() );
	};

	// Status views. Free ships Active and Spam; add-ons (e.g. the Pro trash
	// workflow) add their own via the filter.
	const statuses = useMemo(
		() =>
			applyFilters( 'bonnie.submissions.statuses', [
				{ value: 'active', label: __( 'Active', 'the-bonnie-situation' ) },
				{ value: 'spam', label: __( 'Spam', 'the-bonnie-situation' ) },
			] ),
		[]
	);

	const fields = useMemo( () => {
		const list = [
			{
				id: 'created_at',
				label: __( 'Date', 'the-bonnie-situation' ),
				enableSorting: true,
				enableHiding: false,
				render: ( { item } ) => (
					<a href={ item.view_url }>{ item.created_at_display }</a>
				),
			},
			{
				id: 'form',
				label: __( 'Form', 'the-bonnie-situation' ),
				enableSorting: false,
				elements: data.forms || [],
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item } ) => String( item.form_id ),
				render: ( { item } ) => item.form_title,
			},
			{
				id: 'from',
				label: __( 'From', 'the-bonnie-situation' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item } ) => (
					<span>
						{ item.from_name ? <strong>{ item.from_name }</strong> : null }
						{ item.from_name && item.from_email ? <br /> : null }
						{ item.from_email ? (
							<a href={ `mailto:${ item.from_email }` }>{ item.from_email }</a>
						) : null }
						{ ! item.from_name && ! item.from_email ? '—' : null }
					</span>
				),
			},
			{
				id: 'subject',
				label: __( 'Subject', 'the-bonnie-situation' ),
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'the-bonnie-situation' ),
				enableSorting: true,
				elements: statuses,
				filterBy: { operators: [ 'is' ] },
				render: ( { item } ) =>
					item.status.charAt( 0 ).toUpperCase() + item.status.slice( 1 ),
			},
		];

		if ( data.storeIp ) {
			list.push( {
				id: 'remote_ip',
				label: __( 'IP', 'the-bonnie-situation' ),
				enableSorting: false,
				render: ( { item } ) => item.remote_ip || '',
			} );
		}

		return list;
	}, [ statuses ] );

	const actions = useMemo( () => {
		const base = [
			{
				id: 'view',
				label: __( 'View', 'the-bonnie-situation' ),
				isPrimary: true,
				callback: ( items ) => {
					if ( items[ 0 ] ) {
						window.location.href = items[ 0 ].view_url;
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete permanently', 'the-bonnie-situation' ),
				isDestructive: true,
				supportsBulk: false,
				isEligible: () => true,
				callback: ( items ) => {
					// eslint-disable-next-line no-alert
					if (
						window.confirm(
							__( 'Permanently delete this submission? This cannot be undone.', 'the-bonnie-situation' )
						)
					) {
						runBatch( 'delete', items );
					}
				},
			},
		];

		/**
		 * Filter the DataViews row/bulk actions. Add-ons append their own (e.g.
		 * the Pro trash workflow and bulk delete), using the provided helpers.
		 *
		 * @param {Array}  actions Base actions.
		 * @param {Object} context { runBatch, reload }.
		 */
		return applyFilters( 'bonnie.submissions.actions', base, { runBatch, reload } );
	}, [] );

	return (
		<>
			{ error ? (
				<div className="notice notice-error" style={ { margin: '0 0 12px' } }>
					<p>{ error }</p>
				</div>
			) : null }
			<DataViews
				data={ records }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ pagination }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ isLoading }
				searchLabel={ __( 'Search submissions', 'the-bonnie-situation' ) }
			/>
		</>
	);
}

function mount( id, Component ) {
	const el = document.getElementById( id );
	if ( ! el ) {
		return;
	}
	if ( createRoot ) {
		createRoot( el ).render( <Component /> );
	} else {
		render( <Component />, el );
	}
}

// Defer to domReady so any add-on script has run its top-level addFilter()
// calls before the app reads the `bonnie.submissions.*` filters at render.
domReady( () => {
	mount( 'bonnie-submissions-app', SubmissionsApp );
} );
