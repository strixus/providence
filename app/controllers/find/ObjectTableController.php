<?php
/* ----------------------------------------------------------------------
 * app/controllers/find/ObjectTableController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2008-2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__."/ca/BaseSearchController.php");
require_once(__CA_LIB_DIR__."/ca/Search/ObjectSearch.php");
require_once(__CA_LIB_DIR__."/ca/Browse/ObjectBrowse.php");
require_once(__CA_LIB_DIR__."/core/GeographicMap.php");
require_once(__CA_MODELS_DIR__."/ca_objects.php");
require_once(__CA_MODELS_DIR__."/ca_sets.php");
require_once(__CA_MODELS_DIR__."/ca_set_items.php");
require_once(__CA_MODELS_DIR__."/ca_set_item_labels.php");

class ObjectTableController extends BaseSearchController {
	# -------------------------------------------------------
	/**
	 * Name of subject table (ex. for an object search this is 'ca_objects')
	 */
	protected $ops_tablename = 'ca_objects';

	/**
	 * Number of items per search results page
	 */
	protected $opa_items_per_page = array(8, 16, 24, 32);

	/**
	 * List of search-result views supported for this find
	 * Is associative array: values are view labels, keys are view specifier to be incorporated into view name
	 */
	protected $opa_views;

	/**
	 * List of available search-result sorting fields
	 * Is associative array: values are display names for fields, keys are full fields names (table.field) to be used as sort
	 */
	protected $opa_sorts;

	/**
	 * Name of "find" used to defined result context for ResultContext object
	 * Must be unique for the table and have a corresponding entry in find_navigation.conf
	 */
	protected $ops_find_type = 'basic_search';

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->opa_views = array(
			'list' => _t('list'),
		);

		$this->opa_sorts = array_merge(array(
			'_natural' => _t('relevance'),
			'ca_object_labels.name_sort' => _t('title'),
			'ca_objects.type_id' => _t('type'),
			'ca_objects.idno_sort' => _t('idno')
		), $this->opa_sorts);

		$this->opo_browse = new ObjectBrowse($this->opo_result_context->getParameter('browse_id'), 'providence');

		// overwrite result context
		$this->opo_result_context = new ResultContext($po_request, 'ca_objects', 'ca_objects_table');
		// set dummy search expression so that we don't skip half of
		// the controller code in BaseSearchController::Index()
		$this->opo_result_context->setSearchExpression('table_bundle');
	}
	# -------------------------------------------------------
	/**
	 * Search handler (returns search form and results, if any)
	 * Most logic is contained in the BaseSearchController->Index() method; all you usually
	 * need to do here is instantiate a new subject-appropriate subclass of BaseSearch
	 * (eg. ObjectSearch for objects, EntitySearch for entities) and pass it to BaseSearchController->Index()
	 */
	public function Index($pa_options=null) {
		$pa_options['search'] = $this->opo_browse;
		AssetLoadManager::register('imageScroller');
		AssetLoadManager::register('tabUI');
		AssetLoadManager::register('panel');

		// get request data
		$va_relation_ids = explode(';', $this->getRequest()->getParameter('ids', pString));
		$vs_rel_table = $this->getRequest()->getParameter('relTable', pString);
		$vs_interstitial_prefix = $this->getRequest()->getParameter('interstitialPrefix', pString);
		$vs_primary_table = $this->getRequest()->getParameter('primaryTable', pString);
		$vn_primary_id = $this->getRequest()->getParameter('primaryID', pInteger);

		$va_access_values = caGetUserAccessValues($this->getRequest());

		if (!($vs_sort 	= $this->opo_result_context->getCurrentSort())) {
			$va_tmp = array_keys($this->opa_sorts);
			$vs_sort = array_shift($va_tmp);
		}

		// we need the rel table to translate the incoming relation_ids to object ids for the list search result

		$o_interstitial_res = caMakeSearchResult($vs_rel_table, $va_relation_ids);

		$va_ids = array(); $va_relation_id_map = array();
		while($o_interstitial_res->nextHit()) {
			$va_ids[$o_interstitial_res->get('relation_id')] = $o_interstitial_res->get('ca_objects.object_id');
			$va_relation_id_map[$o_interstitial_res->get('ca_objects.object_id')] = array(
				'relation_id' => $o_interstitial_res->get('relation_id'),
				'relationship_typename' => $o_interstitial_res->getWithTemplate('^relationship_typename')
			);
		}

		$this->getView()->setVar('relationIdMap', $va_relation_id_map);
		$this->getView()->setVar('interstitialPrefix', $vs_interstitial_prefix);
		$this->getView()->setVar('relTable', $vs_rel_table);
		$this->getView()->setVar('primaryTable', $vs_primary_table);
		$this->getView()->setVar('primaryID', $vn_primary_id);

		// piece the parameters back together to build the string to append to urls for subsequent form submissions
		$va_additional_search_controller_params = array(
			'ids' => join(';', $va_relation_ids),
			'interstitialPrefix' => $vs_interstitial_prefix,
			'relTable' => $vs_rel_table,
			'primaryTable' => $vs_primary_table,
			'primaryID' => $vn_primary_id
		);

		$vs_url_string = '';
		foreach($va_additional_search_controller_params as $vs_key => $vs_val) {
			$vs_url_string .= '/' . $vs_key . '/' . urlencode($vs_val);
		}

		$this->getView()->setVar('objectTableURLParamString', $vs_url_string);

		$vs_sort_direction = $this->opo_result_context->getCurrentSortDirection();

		$va_search_opts = array(
			'sort' => $vs_sort,
			'sortDirection' => $vs_sort_direction,
			'checkAccess' => $va_access_values,
			'no_cache' => true,
			'resolveLinksUsing' => $vs_primary_table,
			'primaryIDs' =>
				array (
					$vs_primary_table => array($vn_primary_id),
				),
		);

		$o_res = caMakeSearchResult('ca_objects', array_values($va_ids), $va_search_opts);

		$pa_options['result'] = $o_res;
		$pa_options['view'] = 'Search/ca_objects_table_html.php'; // override render

		$this->getView()->setVar('noRefine', true);

		return parent::Index($pa_options);
	}
	# -------------------------------------------------------
	/**
	 * Returns string representing the name of the item the search will return
	 *
	 * If $ps_mode is 'singular' [default] then the singular version of the name is returned, otherwise the plural is returned
	 */
	public function searchName($ps_mode='singular') {
		return ($ps_mode == 'singular') ? _t("object") : _t("objects");
	}
	# -------------------------------------------------------
}
