<?

defined('C5_EXECUTE') or die(_("Access Denied."));

/**
*
* An object that allows a filtered list of pages to be returned.
* @package Pages
*
*/
class PageList extends DatabaseItemList {

	private $collectionAttributeFilters = array();
	private $collectionAttributeSortFilter = array();
	private $includeSystemPages = false;
	private $displayOnlyPermittedPages = false;
	
	/* magic method for filtering by page attributes. */
	
	public function __call($nm, $a) {
		if (substr($nm, 0, 8) == 'filterBy') {
			$txt = Loader::helper('text');
			$attrib = $txt->uncamelcase(substr($nm, 8));
			if (count($a) == 2) {
				$this->filterByCollectionAttribute($attrib, $a[0], $a[1]);
			} else {
				$this->filterByCollectionAttribute($attrib, $a[0]);
			}
		}			
	}

	/** 
	 * Sorts this list by display order 
	 */
	public function sortByDisplayOrder() {
		parent::sortBy('p1.cDisplayOrder', 'asc');
	}
	
	/** 
	 * Sorts this list by display order descending 
	 */
	public function sortByDisplayOrderDescending() {
		parent::sortBy('cDisplayOrder', 'desc');
	}
	
	/** 
	 * Sorts this list by public date ascending order 
	 */
	public function sortByPublicDate() {
		parent::sortBy('cvDatePublic', 'asc');
	}
	
	/** 
	 * Sorts this list by name 
	 */
	public function sortByName() {
		parent::sortBy('cvName', 'asc');
	}
	
	/** 
	 * Sorts this list by name descending order
	 */
	public function sortByNameDescending() {
		parent::sortBy('cvName', 'desc');
	}

	/** 
	 * Sorts this list by public date descending order 
	 */
	public function sortByPublicDateDescending() {
		parent::sortBy('cvDatePublic', 'desc');
	}	
	
	/** 
	 * Sets the parent ID that we will grab pages from. 
	 * @param mixed $cParentID
	 */
	public function filterByParentID($cParentID) {
		$this->filter('p1.cParentID', $cParentID);
	}
	
	/** 
	 * Filters by type of collection (using the ID field)
	 * @param mixed $ctID
	 */
	public function filterByCollectionTypeID($ctID) {
		$this->filter(false, "(p1.ctID = $ctID or p2.ctID = $ctID)");
	}

	/** 
	 * Filters by type of collection (using the handle field)
	 * @param mixed $ctID
	 */
	public function filterByCollectionTypeHandle($ctHandle) {
		$db = Loader::db();
		if (is_array($ctHandle)) {
			$cth = '(';
			for ($i = 0; $i < count($ctHandle); $i++) {
				if ($i > 0) {
					$cth .= ',';
				}
				$cth .= $db->quote($ctHandle[$i]);
			}
			$cth .= ')';
			$this->filter(false, "(pt1.ctHandle in {$cth} or pt2.ctHandle in {$cth})");
		} else {
			$this->filter(false, "(pt1.ctHandle = " . $db->quote($ctHandle) . " or pt2.ctHandle = " . $db->quote($ctHandle) . ")");
		}
	}

	/** 
	 * Filters by public date
	 * @param string $date
	 */
	public function filterByPublicDate($date, $comparison = '=') {
		$this->filter('cv.cvDatePublic', $date, $comparison);
	}
	
	/** 
	 * Filters the list by collection attribute
	 * @param string $handle Collection Attribute Handle
	 * @param string $value
	 */
	public function filterByCollectionAttribute($handle, $value, $comparison = '=') {
		$this->collectionAttributeFilters[] = array($handle, $value, $comparison);
	}
	
	public function sortByCollectionAttribute($handle, $order = 'asc') {
		$this->collectionAttributeSortFilter = array($handle, $order);
	}
	
	/** 
	 * If true, pages will be checked for permissions prior to being returned
	 * @param bool $checkForPermissions
	 */
	public function displayOnlyPermittedPages($checkForPermissions) {
		$this->displayOnlyPermittedPages = $checkForPermissions;
	}
	
	protected function setBaseQuery() {
		$this->setQuery('select p1.cID, if(p2.cID is null, pt1.ctHandle, pt2.ctHandle) as ctHandle from Pages p1 left join Pages p2 on (p1.cPointerID = p2.cID) left join PageTypes pt1 on (pt1.ctID = p1.ctID) left join PageTypes pt2 on (pt2.ctID = p2.ctID) inner join CollectionVersions cv on (cv.cID = if(p2.cID is null, p1.cID, p2.cID))');
	}
	
	protected function setupCollectionAttributeFilters() {
		$db = Loader::db();
		
		foreach($this->collectionAttributeFilters as $caf) {
			$akID = $db->GetOne("select akID from CollectionAttributeKeys where akHandle = ?", array($caf[0]));
			if (!$akID) {
				$akID = 0;
			}
			$tbl = "cav_{$akID}";
			$this->addToQuery("left join CollectionAttributeValues $tbl on {$tbl}.cID = if(p2.cID is null, p1.cID, p2.cID) and {$tbl}.akID = {$akID} and cv.cvID = {$tbl}.cvID");
			$this->filter($tbl . '.value', $caf[1], $caf[2]);
			$this->filter($tbl . '.akID', $akID);
			
			if (isset($this->collectionAttributeSortFilter[0]) && $this->collectionAttributeSortFilter[0] == $caf[0]) {
				$sortByTable = $tbl;
			}
		}
		
		if (!isset($sortByTable) && (isset($this->collectionAttributeSortFilter[0]))) {
			$akID = $db->GetOne("select akID from CollectionAttributeKeys where akHandle = ?", array($this->collectionAttributeSortFilter[0]));
			$tbl = "cav_{$akID}";			
			$this->addToQuery("left join CollectionAttributeValues $tbl on {$tbl}.cID = if(p2.cID is null, p1.cID, p2.cID) and {$tbl}.akID = {$akID} and cv.cvID = {$tbl}.cvID");
			$sortByTable = $tbl;
		}
		
		if (isset($sortByTable)) {
			parent::sortBy($sortByTable . '.value', $this->collectionAttributeSortFilter[1]);
		}
	}
	
	/** 
	 * Returns an array of page objects based on current settings
	 */
	public function get($itemsToGet = 0, $offset = 0) {
		$pages = array();
		$this->setBaseQuery();
		$this->filter('cvIsApproved', 1);
		$this->filter(false, "(p1.cIsTemplate = 0 or p2.cIsTemplate = 0)");
		$this->setItemsPerPage(0); // no limit
		$this->setupCollectionAttributeFilters();
		$this->setupCollectionAttributeSortFilters();
		$r = parent::get();
		foreach($r as $row) {
			$nc = Page::getByID($row['cID']);
			$nc->loadVersionObject();
			if ($nc->isSystemPage()) {
				continue;
			}
			$pages[] = $nc;
			if (count($pages) == $itemsToGet) {
				break;
			}
		}
		return $pages;
	}
	
}
