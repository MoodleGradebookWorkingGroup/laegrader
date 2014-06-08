<?php
require_once $CFG->dirroot.'/lib/grade/grade_item.php';
require_once $CFG->dirroot.'/lib/grade/grade_category.php';
require_once $CFG->dirroot.'/lib/grade/grade_object.php';
require_once $CFG->dirroot.'/grade/edit/tree/lib.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once($CFG->dirroot.'/grade/export/lib.php');

function grade_tree_local_helper($courseid, $fillers=false, $category_grade_last=true, $collapsed=null, $nooutcomes=false, $currentgroup, $sumofgradesonly=null) {
    global $CFG;
    $CFG->currentgroup = $currentgroup;
    return new grade_tree_local($courseid, $fillers, $category_grade_last, $collapsed, $nooutcomes);
}


class grade_tree_local extends grade_tree {

    /**
     * The basic representation of the tree as a hierarchical, 3-tiered array.
     * @var object $top_element
     */
    public $top_element;

    /**
     * 2D array of grade items and categories
     * @var array $levels
     */
    public $levels;

    /**
     * Grade items
     * @var array $items
     */
    public $items;

    /**
     * LAE Grade items used for cycling through the get_right_rows
     * @var array $items
     */
    public $levelitems;

    /**
     * LAE structure used to get the damn category names into the category-item object
     * @var array $items
     */
    public $catitems;

    /**
     * Constructor, retrieves and stores a hierarchical array of all grade_category and grade_item
     * objects for the given courseid. Full objects are instantiated. Ordering sequence is fixed if needed.
     *
     * @param int   $courseid The Course ID
     * @param bool  $fillers include fillers and colspans, make the levels var "rectangular"
     * @param bool  $category_grade_last category grade item is the last child
     * @param array $collapsed array of collapsed categories
     * @param bool  $nooutcomes Whether or not outcomes should be included
     */
    public function grade_tree_local($courseid, $fillers=false, $category_grade_last=true,
                               $collapsed=null, $nooutcomes=false) {
        global $USER, $CFG, $COURSE, $DB;

        $this->courseid   = $courseid;
        $this->levels     = array();
        $this->context    = context_course::instance($courseid);

        if (!empty($COURSE->id) && $COURSE->id == $this->courseid) {
            $course = $COURSE;
        } else {
            $course = $DB->get_record('course', array('id' => $this->courseid));
        }
        $this->modinfo = get_fast_modinfo($course);

        // get course grade tree
        $this->top_element = grade_category::fetch_course_tree($courseid, true);

        // no otucomes if requested
        if (!empty($nooutcomes)) {
            grade_tree_local::no_outcomes($this->top_element);
        }

        // move category item to last position in category
        if ($category_grade_last) {
            grade_tree_local::category_grade_last($this->top_element);
        }

        // provide for crossindexing of modinfo and grades in the case of display by group so items not assigned to a group can be omitted
        // first determine if enablegroupmembersonly is on, then determine if groupmode is set (separate or visible)
/*
        $this->modx = array();
        if ($CFG->enablegroupmembersonly && $CFG->currentgroup > 0 ) {
            $groupingsforthisgroup = $DB->get_fieldset_select('groupings_groups', 'groupingid', " groupid = $CFG->currentgroup ");
            $groupingsforthisgroup = implode(',', $groupingsforthisgroup);

            // get all the records for items that SHOULDN'T be included
            $sql = "SELECT gi.id FROM " .  $CFG->prefix . "grade_items gi, " . $CFG->prefix . "modules m, " . $CFG->prefix . "course_modules cm
                    WHERE m.name = gi.itemmodule
                    AND cm.instance = gi.iteminstance
                    AND cm.module = m.id
                    AND gi.courseid = $courseid
                    AND cm.groupingid <> 0
                    AND cm.groupingid NOT IN($groupingsforthisgroup)";
            $this->modx = $DB->get_records_sql($sql);
        }
*/
        // key to LAE grader, no levels
        grade_tree_local::fill_levels($this->levels, $this->top_element, 0);
        grade_tree_local::fill_levels_local($this->levelitems, $this->top_element, 0);
        if (!$category_grade_last) {
            $this->levelitems = array_reverse($this->levelitems, true);
        }
    }
    /**
     * Static recursive helper - fills the levels array, useful when accessing tree elements of one level
     *
     * @param array &$levels The levels of the grade tree through which to recurse
     * @param array &$element The seed of the recursion
     * @param int   $depth How deep are we?
     * @return void
     */

    public function fill_levels_local(&$levelitems, &$element, $depth) {

/*        if (array_key_exists($element['object']->id, $this->modx)) { // don't include something made only for a different group
            return;
        } else */
        if ($element['type'] == 'category') { // prepare unique identifier
            $element['eid'] = 'c'.$element['object']->id;
            $this->catitems[$element['object']->id] = $element['object']->fullname;
        } else if (in_array($element['type'], array('item', 'courseitem', 'categoryitem'))) {
            $element['eid'] = 'i'.$element['object']->id;
            $this->items[$element['object']->id] =& $element['object'];
            $this->levelitems[$element['object']->id] =& $element;
            if ($element['type'] == 'categoryitem' && array_key_exists($element['object']->iteminstance,$this->catitems)) {
                $this->items[$element['object']->id]->itemname = $this->catitems[$element['object']->iteminstance];
            }
        }

        if (empty($element['children'])) {
            return;
        }
        $prev = 0;
        foreach ($element['children'] as $sortorder=>$child) {
            grade_tree_local::fill_levels_local($this->levelitems, $element['children'][$sortorder], $depth);
        }
    }

     /**
     * Returns name of element optionally with icon and link
     * USED BY LAEGRADER IN ORDER TO WRAP GRADE TITLES IN THE HEADER
     *
     * @param array &$element An array representing an element in the grade_tree
     * @param bool  $withlink Whether or not this header has a link
     * @param bool  $icon Whether or not to display an icon with this header
     * @param bool  $spacerifnone return spacer if no icon found
     *
     * @return string header
     */
    function get_element_header_local(&$element, $withlink=false, $icon=true, $spacerifnone=false, $titlelength = null, $catname) {
        $header = '';

        switch ($element['type']) {
            case 'courseitem':
                $header .= get_string('coursetotal', 'gradereport_laegrader');
                break;
            case 'categoryitem':
                $header .= get_string('categorytotal', 'gradereport_laegrader');
            default:
                $header .= $catname . ' ';
        }
        if ($element['object']->weight > 1) {
            $header .= ' W=' . format_float($element['object']->weight,1, true, true) . '% ';
        }
        if ($titlelength) {
            $header = wordwrap($header, $titlelength, '<br />');
        }

        if ($icon) {
            $header = $this->get_element_icon($element, $spacerifnone) . $header;
        }

        if ($withlink) {
            $url = $this->get_activity_link($element);
            if ($url) {
                $a = new stdClass();
                $a->name = get_string('modulename', $element['object']->itemmodule);
                $title = get_string('linktoactivity', 'grades', $a);

                $header = html_writer::link($url, $header, array('title' => $title));
            }
        }

        return $header;
    }

    /*
     * LAE NEED TO INCLUDE THIS BECAUSE ITS THE ONLY WAY TO GET IT CALLED BY get_element_header ABOVE
     */
    private function get_activity_link($element) {
        global $CFG;
        /** @var array static cache of the grade.php file existence flags */
        static $hasgradephp = array();

        $itemtype = $element['object']->itemtype;
        $itemmodule = $element['object']->itemmodule;
        $iteminstance = $element['object']->iteminstance;
        $itemnumber = $element['object']->itemnumber;

        // Links only for module items that have valid instance, module and are
        // called from grade_tree with valid modinfo
        if ($itemtype != 'mod' || !$iteminstance || !$itemmodule || !$this->modinfo) {
            return null;
        }

        // Get $cm efficiently and with visibility information using modinfo
        $instances = $this->modinfo->get_instances();
        if (empty($instances[$itemmodule][$iteminstance])) {
            return null;
        }
        $cm = $instances[$itemmodule][$iteminstance];

        // Do not add link if activity is not visible to the current user
        if (!$cm->uservisible) {
            return null;
        }

        if (!array_key_exists($itemmodule, $hasgradephp)) {
            if (file_exists($CFG->dirroot . '/mod/' . $itemmodule . '/grade.php')) {
                $hasgradephp[$itemmodule] = true;
            } else {
                $hasgradephp[$itemmodule] = false;
            }
        }

        // If module has grade.php, link to that, otherwise view.php
        if ($hasgradephp[$itemmodule]) {
            $args = array('id' => $cm->id, 'itemnumber' => $itemnumber);
            if (isset($element['object']->userid)) {
                $args['userid'] = $element['object']->userid;
            }
            return new moodle_url('/mod/' . $itemmodule . '/grade.php', $args);
        } else {
            return new moodle_url('/mod/' . $itemmodule . '/view.php', array('id' => $cm->id));
        }
    }
    /**
     * Returns a specific Grade Item
     *
     * @param int $itemid The ID of the grade_item object
     *
     * @return grade_item
     * TODO: check if we really need this function, I think we do
     */
    public function get_item($itemid) {
        if (array_key_exists($itemid, $this->items)) {
            return $this->items[$itemid];
        } else {
            return false;
        }
    }

    /**
     * Parses the array in search of a given eid and returns a element object with
     * information about the element it has found.
     * @param int $id Gradetree item ID
     * @return object element
     * LAE we don't use the standard tree (somebody say, "INEFFICIENT!!") so need local function
     */
/*
    public function locate_element($id) {
        // it is a category or item
        foreach ($this->levelitems as $key=>$element) {
            if ($key == $id) {
                return $element;
            }
        }

        return null;
    }
*/

    function accuratepointsprelimcalculation (&$grades, $usetargets = false, $fullweight = null) {
        // plugin target grade for final

        foreach ($grades as $grade) {
            $grade->extracredit = 0;
        }
        foreach ($this->levelitems as $itemid => $item) {

            $type = $item['type'];
//          $itemid = $item['object']->id;
            if (!isset($grades[$itemid])) {
                $grades[$itemid] = new stdClass();
            }
            $grade = $grades[$itemid];
            $grade_values = array();
            $grade_maxes = array();

            // get the id of this grade's parent
            if ($type !== 'course' && $type !== 'courseitem') {
                $parent_id = $this->parents[$itemid]->parent_id; // the parent record contains an id field pointing to its parent, the key on the parent record is the item itself to allow lookup
            }

            // assign array values to grade_values and grade_maxes for later use
            if ($type == 'categoryitem' || $type == 'courseitem' || $type == 'category' || $type == 'course') { // categoryitems or courseitems
                if (isset($grades[$itemid]->cat_max)) { // if category or course has marked grades in it
                    // set up variables that are used in this inserted limit_rules scrap
                    $this->cat = $this->items[$itemid]->get_item_category(); // need category settings like drop-low or keep-high

                    // copy cat_max to a variable we can send along with this limit_item
                    if (!$fullweight) {
                        $this->limit_item($itemid, $grades); // TODO: test this with some drop-low conditions to see if we can still ascertain the weighted grade
                        $grade_values = $grades[$itemid]->cat_item; // earned points
                    }
                    if (isset($grades[$parent_id]->weight)) {
                    	$parent_weight = $grades[$parent_id]->weight * .01;
                    } else {
                    	$parent_weight = 1;
                    }
                    $grade_maxes = $grades[$itemid]->cat_max; // range of earnable points for marked items
//		            $grades[$parent_id]->pctg[$itemid] = array_sum($grades[$itemid]->pctg) * $grade->weight; // need to supply the container with the percentage for the item
//                    $grades[$parent_id]->contrib[$itemid] = $gradeval / $grade->grade_item->grademax * $grade->weight * $parent_weight * .01; // need to supply the container with the percentage for the item
                }
            } else { // items
                if ($usetargets && is_null($grade->finalgrade)) {
                    $gradeval = $this->items[$itemid]->target;
                } else if (!$fullweight) {
                    $gradeval = $grade->finalgrade;
                }
                if ($this->items[$itemid]->is_hidden() && ($fullweight || $this->showtotalsifcontainhidden == GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN)) {
                    continue;
                } else if ($this->items[$itemid]->extracredit == 1) { // extra credit
                    $grades[$parent_id]->extracredit += $gradeval;
                } else if ($fullweight) {
                    $grades[$parent_id]->cat_max[$itemid] = $this->items[$itemid]->grademax;
                } else if (!isset($gradeval)) {
                    continue;
                } else {
                    // fill parent's array with information from this grade
                    $grades[$parent_id]->cat_item[$itemid] = $gradeval;
                    $grades[$parent_id]->cat_max[$itemid] = $grade->grade_item->grademax;
                    if (isset($grades[$parent_id]->weight)) {
                    	$parent_weight = $grades[$parent_id]->weight * .01;
                    } else {
                    	$parent_weight = 1;
                    }
//		            $grades[$parent_id]->pctg[$itemid] = $gradeval / $grade->grade_item->grademax * $grade->weight; // need to supply the container with the percentage for the item
//                    $grades[$parent_id]->contrib[$itemid] = $gradeval / $grade->grade_item->grademax * $grade->weight * $parent_weight * .01; // need to supply the container with the percentage for the item
                }
            }

            if (!isset($grade_maxes) || sizeof($grade_maxes) == 0 || $type === 'item') {
                // do nothing
            } else if ($type == 'category' || $type == 'categoryitem') {
                // if we have a point value or if viewing an empty report
                // if (isset($gradeval) || $this->user->id == $USER->id) {

                // preparing to deal with extra credit which would have an agg_coef of 1 if not WM
                if ($grades[$itemid]->extracredit == 1) {
                    $grades[$parent_id]->extracredit += array_sum($grade_values);
//                } else if ($grades[$itemid]->weight == -1) {
//                	continue;
                } else {
                    // continue adding to the array under the parent object
                    $grades[$parent_id]->cat_item[$itemid] = array_sum($grade_values) + $grades[$itemid]->extracredit; // earned points
                    $grades[$parent_id]->cat_max[$itemid] = array_sum($grade_maxes); // range of earnable points
//                    $grades[$parent_id]->contrib[$itemid] = (array_sum($grades[$itemid]->contrib));
//		            $grades[$parent_id]->pctg[$itemid] = $grades[$parent_id]->contrib[$itemid] * $grade->weight * .01;
                }
                $this->items[$itemid]->grademax = array_sum($grade_maxes);
            } else { // calculate up the weighted percentage for the course item
//                $grades[$itemid]->coursepctg = array_sum($grades[$itemid]->contrib) + $grades[$itemid]->extracredit;
                $this->items[$itemid]->grademax = array_sum($grade_maxes);
            }
        }
    }

    public function accuratepointsfinalvalues(&$grades, $itemid, &$item, $type, $parent_id, $gradedisplaytype = GRADE_DISPLAY_TYPE_REAL) {
        $current_cat = $this->items[$itemid]->get_item_category(); // need category settings like drop-low or keep-high
        if (!isset($grades[$itemid]->cat_item)) {
            $gradeval = 0;
        } else {
            switch ($gradedisplaytype) {
                case GRADE_DISPLAY_TYPE_REAL:
                    $grade_values = $grades[$itemid]->cat_item;
                    $grade_maxes = $grades[$itemid]->cat_max;
//                  $grade_pctg = $grades[$itemid]->pctg;
                    $gradeval = array_sum($grade_values) + $grades[$itemid]->extracredit;
                    $item->grademax = array_sum($grade_maxes);
                    break;
                case GRADE_DISPLAY_TYPE_LETTER:
                case GRADE_DISPLAY_TYPE_PERCENTAGE: // only gets called if category or course
//                  $gradeval = $type == 'category' ? array_sum($grades[$itemid]->pctg) / sizeof($grades[$itemid]->pctg) : $grades[$itemid]->coursepctg;
                    $catpctg = 0;
                    $catweights = 0;
                    foreach ($grades[$itemid]->pctg as $id => $pctg) {
                        $catpctg += ($pctg * $grades[$id]->weight);
                        $catweights += $grades[$id]->weight;
                    }
                    $gradeval = $catpctg / $catweights;
                    if (isset($grades[$parent_id])) {
                        $grades[$parent_id]->pctg[$itemid] = $gradeval;
                    }
//                  $gradeval = $type == 'category' ? $grades[$parent_id]->pctg[$itemid] : $grades[$itemid]->coursepctg;
                    $item->grademax = 1;
                    break;
            }
        }
        return $gradeval;
    }

    /**
     * Return hiding icon for give element
     *
     * @param array  $element An array representing an element in the grade_tree
     * @param object $gpr A grade_plugin_return object
     *
     * @return string
     */
    public function get_zerofill_icon($element, $gpr) {
        global $CFG, $OUTPUT;

        if (!has_capability('moodle/grade:manage', $this->context) and
            !has_capability('moodle/grade:hide', $this->context)) {
            return '';
        }

        $strparams = $this->get_params_for_iconstr($element);
        $strzerofill = get_string('zerofill', 'gradereport_laegrader', $strparams);

        $url = new moodle_url('/grade/report/laegrader/index.php', array('id' => $this->courseid, 'sesskey' => sesskey(), 'action' => 'quickdump'));
        $url = $gpr->add_url_params($url);

        $type = 'zerofill';
        $tooltip = $strzerofill;
        $actiontext = '<img alt="' . $type . '" class="smallicon" title="' . $strzerofill . '" src="' . $CFG->wwwroot . '/grade/report/laegrader/images/zerofill.png" />';
        $url->param('action', 'zerofill');
        $zerofillicon = $OUTPUT->action_link($url, 'text', null, array('class' => 'action-icon', 'onclick'=>'zerofill(' . $element['object']->id . ')'));
        preg_match('/(.*href=")/',$zerofillicon, $matches);
        // sending back an empty href with onclick
        $zerofillicontemp = $matches[0] . '#">' . $actiontext . '</a>';
        return $zerofillicontemp;
    }

    public function get_clearoverrides_icon($element, $gpr) {
        global $CFG, $OUTPUT;

        if (!has_capability('moodle/grade:manage', $this->context) and
            !has_capability('moodle/grade:hide', $this->context)) {
            return '';
        }

        $strparams = $this->get_params_for_iconstr($element);
        $strclearoverrides = get_string('clearoverrides', 'gradereport_laegrader', $strparams);

        if ($element['type'] == 'item') {
            $url = new moodle_url('/grade/report/laegrader/index.php', array('id' => $this->courseid, 'sesskey' => sesskey(), 'action' => 'clearoverrides', 'itemid'=>$element['object']->id));
        } else {
            $element['object']->load_grade_item();
            $url = new moodle_url('/grade/report/laegrader/index.php', array('id' => $this->courseid, 'sesskey' => sesskey(), 'action' => 'clearoverrides', 'itemid'=>$element['object']->grade_item->id));
        }

        $type = 'clearoverrides';
        $tooltip = $strclearoverrides;
        $actiontext = '<img alt="' . $type . '" class="smallicon" title="' . $strclearoverrides . '" src="' . $CFG->wwwroot . '/grade/report/laegrader/images/clearoverrides.gif" />';
        $clearoverrides = $OUTPUT->action_link($url, $actiontext, null, array('class' => 'action-icon'));

        return $clearoverrides;
    }

    public function get_changedisplay_icon($element) {
        global $CFG, $OUTPUT;

        if (!has_capability('moodle/grade:manage', $this->context) and
            !has_capability('moodle/grade:hide', $this->context)) {
            return '';
        }

        $strparams = $this->get_params_for_iconstr($element);
        $strchangedisplay = get_string('changedisplay', 'gradereport_laegrader', $strparams);

        $url = new moodle_url('/grade/report/laegrader/index.php', array('id' => $this->courseid, 'sesskey' => sesskey(), 'action' => 'changedisplay', 'itemid'=>$element['object']->id));

        $type = 'changedisplay';
        $tooltip = $strchangedisplay;
        $actiontext = '<img alt="' . $type . '" title="' . $strchangedisplay . '" src="' . $CFG->wwwroot . '/grade/report/laegrader/images/changedisplay.png" />';
        $changedisplay = $OUTPUT->action_link($url, $actiontext, null, array('class' => 'action-icon'));

        return $changedisplay;
    }

    /**
     * Return edit icon for give element
     *
     * @param array  $element An array representing an element in the grade_tree
     * @param object $gpr A grade_plugin_return object
     *
     * @return string
     */
    public function get_weight_edit_icon() {
        global $CFG, $OUTPUT;

        if (!has_capability('moodle/grade:manage', $this->context)) {
            return '';
        }

        $streditweight = get_string('editweight', 'gradereport_laegrader');

        $object = $this->modinfo;
        $url = new moodle_url('/grade/edit/tree/index.php',
                array('id' => $this->courseid, 'userid' => $object->userid, 'action' => 'editweights'));
        if ($url) {
            return $OUTPUT->action_icon($url, new pix_icon('t/edit', $streditweight));
        } else {
            return '';
        }
    }

    function limit_item($itemid, $grades, $unsetgrades = true) {
        $extraused = $this->cat->is_extracredit_used();
        if (!empty($this->cat->droplow)) {
            asort($grades[$itemid]->contrib, SORT_NUMERIC);
            $dropped = 0;
            foreach ($grades[$itemid]->contrib as $childid=>$contrib) {
                if ($dropped < $this->cat->droplow) {
                    if (is_null($contrib)) {
                        continue;
                    } else if ($extraused && $this->cat->aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN2 && $this->items[$childid]->aggregationcoef > 0) {
                        // no drop low for extra credits
                    } else {
                        if ($unsetgrades) {
                            unset($grades[$itemid]->pctg[$childid]);
//                            unset($grades[$itemid]->cat_item[$childid]);
//                            unset($grades[$itemid]->cat_max[$childid]);
                            unset($grades[$itemid]->contrib[$childid]);
                        	$grades[$childid]->weight = -1; // need to set the weight here because calc_weights doesn't consider drop or keep conditions
                        	$dropped++;
                        }
                    }
                } else {
                    // we have dropped enough
                    break;
                }
            }
        } else if (!empty($this->cat->keephigh)) {
            arsort($grades[$itemid]->contrib, SORT_NUMERIC);
            $kept = 0;
            foreach ($grades[$itemid]->contrib as $childid=>$contrib) {
                if (is_null($contrib)) {
                    continue;
                } else if ($extraused && $this->cat->aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN2 && $this->items[$childid]->aggregationcoef > 0) {
                    // we keep all extra credits
                } else if ($kept < $this->cat->keephigh) {
                    $kept++;
                } else {
                    if ($unsetgrades) {
                        unset($grades[$itemid]->pctg[$childid]);
//                        unset($grades[$itemid]->cat_item[$childid]);
//                        unset($grades[$itemid]->cat_max[$childid]);
                        unset($grades[$itemid]->contrib[$childid]);
                    	$grades[$childid]->weight = -1; // need to set the weight here because calc_weights doesn't consider drop or keep conditions
                    }
                }
            }
        }
    }

    /*
     * LAE keeps track of the parents of items in case we need to actually compute accurate point totals instead of everything = 100 points (same as percent, duh)
     * Recursive function for filling gtree-parents array keyed on item-id with elements id=itemid of parent, agg=aggtype of item
     * accumulates $items->max_earnable with either the child's max_earnable or (in the case of a non-category) grademx
     * @param array &$parents - what is being built in order to allow accurate accumulation of child elements' grademaxes (and earned grades) into the container element (category or course)
     * @param array &$items - the array of grade item objects
     * @param array $cats - array of category information used to get the actualy itemid for the child category cuz its not otherwise in item object
     * @param object $element - level element which allows a top down approach to a bottom up procedure (i.e., find the children and store their accumulated values to the parents)
     * @param boolean $accuratetotals - if user wants to see accurate point totals for their gradebook
     * @param boolean $alltotals -- this is passed by the user report because max_earnable can only be figured on graded items
     */
    function fill_parents($element, $idnumber, $showtotalsifcontainhidden = 0) {
        foreach($element['children'] as $sortorder=>$child) {
            // skip items that are only for another group than the one being considered
/*            if (array_key_exists($child['object']->id, $this->modx)) {
                continue;
            }
*/
            switch ($child['type']) {
                case 'courseitem':
                case 'categoryitem':
                    continue 2;
                case 'category':
                    $childid = $this->cats[$child['object']->id]->id;
                    break;
                default:
                    $childid = substr($child['eid'],1,8);
            }
            if (!isset($this->parents[$childid])) {
                $this->parents[$childid] = new stdClass();
                $this->parents[$childid]->cat_item = array();
                $this->parents[$childid]->cat_max = array();
                $this->parents[$childid]->pctg = array();
                $this->parents[$childid]->agg_coef = array();
                $this->parents[$childid]->weightoverride = $this->items[$childid]->weightoverride;
                $this->parents[$childid]->parent_id = $idnumber;
                $this->parents[$childid]->parent_agg = $element['object']->aggregation;
                $this->parents[$childid]->extracredit = 0;
            }
            if (! empty($child['children'])) {
                $this->fill_parents($child, $childid, $showtotalsifcontainhidden);
            }


            // accumulate max scores for parent
            // this is needed for cats and items since we don't have grades to attach max values to
    //        if ($accuratetotals && $alltotals) {
            // this line needs to determine whether to include hidden items
            if ((!$child['object']->is_hidden() || $showtotalsifcontainhidden == GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN) // either its not hidden or the hiding setting allows it to be calculated into the total
                    && isset($this->parents[$childid]->parent_id) // the parent of this item needs to be set
                    && $this->items[$childid]->extracredit !== 1) {
//                    && ((isset($this->items[$childid]->aggregationcoef) && $this->items[$childid]->aggregationcoef != 1) // isn't an extra credit item -- has a weight and the weight isn't 1
//                    || (isset($this->parents[$childid]->parent_agg) && $this->parents[$childid]->parent_agg == GRADE_AGGREGATE_WEIGHTED_MEAN))) { // or has a weight but in a category using WM
                $this->items[$idnumber]->max_earnable += (isset($this->items[$childid]->max_earnable)) ? $this->items[$childid]->max_earnable : $this->items[$childid]->grademax;
            }
        }
        return;
    }

    /*
     * LAE need in order to get hold of the category name for the categoryitem structure without using the upper level category which we don't use
    */
    function fill_cats() {
        foreach($this->items as $key=>$item) {
            if (!$item->categoryid) {
                $this->cats[$item->iteminstance] = $item;
            }
        }
    }

    /*
     *
     */
    function calc_weights_recursive2(&$element, &$grades, $unset, $fullweight = null) {
        global $DB;
        // determine any overridden weights
        $sql = "SELECT id, weight, weightoverride
                FROM {grade_items}
                WHERE courseid = $this->courseid
                AND weightoverride != 0";
        $checkitems = $DB->get_records_sql($sql);

        switch ($element['type']) {
            case 'grade_item':
            case 'item':
                $elementid = $element['object']->id;
                break;
            case 'categoryitem':
            case 'courseitem':
                return;
            default:
                $elementid = $element['object']->grade_item->id;
                if ($element['object']->grade_item->itemtype == 'course') {
                	$grades[$element['object']->grade_item->id]->weight = 100;
                }
                break;
        }
        if ($fullweight) {
        	$grades[$elementid]->grade_item = new stdClass;
        	$grades[$elementid]->grade_item->grademax = $grades[$elementid]->grademax;
        }

        $container_weight = $grades[$elementid]->grade_item->grademax; // because Colin's work has already substituted correct values
//        $container_weight = $this->items[$elementid]->grademax;
        // build the weight for category or course, we don't go any further if not a container
        if (isset($element['children'])) {
            $contribution = 0; // what's already been marked, if from cats and items - everything
            $missing_weight = 0; // what's not been marked, meaningless from cats and items
            $override_weight = 0; //
            if (!isset($this->items[$elementid]->max_earnable)) {
                $this->items[$elementid]->max_earnable = 0;
            }

        	/// Recursively iterate through all child elements
            foreach ($element['children'] as $key => $child) {
                if ($child['type'] === 'categoryitem' || $child['type'] === 'courseitem') {
                    continue; // do nothing with these types of elements
                }
            	if ($child['object'] instanceof grade_category) {
//					if ($fullweight) {
	            		$child['object']->load_grade_item();
//					}
                    $id = $child['object']->grade_item->id;
                } else {
                    $id = $child['object']->id;
                }

                // check to see if this is a category with no visible children and if so, should we ignore it
                $exitval = false;
                if ($child['type'] === 'category' && ($fullweight || $this->showtotalsifcontainhidden !== GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN)) {
                    foreach ($child['children'] as $key => $grandchild) {
                        if ($grandchild['type'] !== 'categoryitem' && !$grandchild['object']->is_hidden() && $grades[$grandchild['object']->id]->finalgrade != 0 &&(!isset($grades[$grandchild['object']->id]->weight) || $grades[$grandchild['object']->id]->weight != -1)) {
                            $exitval = true;
                            break;
                        }
                    }
                    if (!$exitval) {
                        $this->emptycats[$id] = 'empty';
                        continue; // if all are hidden then don't count this into calculations
                    }
                }

                // check to see if weights are overridden
                if (array_key_exists($id, $checkitems)) {
            		$override_weight += $checkitems[$id]->weight;
                	$grades[$id]->weight = $checkitems[$id]->weight;
            	}
            }

            // determine how much non-normalized weight we already have in the category and how much might be missing (in case we have a target grade condition)
            // also determine the relative contibution so far
            foreach ($element['children'] as $order => $child) {
                if ($child['object'] instanceof grade_category) {
//                    $child['object']->load_grade_item();
                    $id = $child['object']->grade_item->id;
                } else {
                    $id = $child['object']->id;
                }

                if ($fullweight) {
                	$grades[$id]->grade_item = new stdClass;
                	$grades[$id]->grade_item->grademax = $grades[$id]->grademax;
                }
                // check to see if this is a category with no visible children
                $exitval = false;
                if ($child['type'] === 'category' && ($fullweight || $this->showtotalsifcontainhidden !== GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN)) {
                    foreach ($child['children'] as $key => $grandchild) {
                        if ($grandchild['type'] !== 'categoryitem' && !$grandchild['object']->is_hidden() && $grades[$grandchild['object']->id]->finalgrade != 0 &&(!isset($grades[$grandchild['object']->id]->weight) || $grades[$grandchild['object']->id]->weight != -1)) {
                            $exitval = true;
                            break;
                        }
                    }
                    if (!$exitval) {
                        $this->emptycats[$id] = 'empty';
                        continue; // if all are hidden then don't count this into calculations
                    }
                }

                if ($child['type'] === 'categoryitem' || $child['type'] === 'courseitem') {
                    continue; // do nothing with these types of elements
                } else if ($this->items[$id]->is_hidden()) { // either its not hidden or the hiding setting allows it to be calculated into the total
                    continue;
                } else if ($this->items[$id]->is_hidden() && ($fullweight || $this->showtotalsifcontainhidden !== GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN)) { // either its not hidden or the hiding setting allows it to be calculated into the total
                    continue;
                } else if (isset($grades[$id]->weight) && $grades[$id]->weight == -1) { // has been dropped or not kept
                    continue;
                } else if (array_key_exists($id, $checkitems)) {
//                    $contribution += $grades[$id]->grade_item->grademax;
                	continue; // already dealt with
                } else if (array_key_exists($id, $this->emptycats)) {
                	continue; // already dealt with
                } else if ($this->items[$id]->extracredit == 1 && !$fullweight) { // extra credit is removed from the calculation making up the container's weight
                    // do nothing, extra credit has weight over and above container weight
                } else if ($fullweight) { // meaning we're coming from cats and items
                    $contribution += $grades[$id]->grade_item->grademax;
//                	$contribution += $this->items[$id]->grademax;
                } else if (!isset($grades[$id]->finalgrade)) {
                    $missing_weight += $grades[$id]->grade_item->grademax;
                    $this->emptygrades[$id] = $this->items[$id];
                } else { // has marks
                    $contribution += $grades[$id]->grade_item->grademax;
                }
            }

            // how much potential grades are left to be earned
            // normalizer adjust the weights to be equal to 100
            // weight adjuster is multiplied by the child's weight to achieve the right percentage of the container weight
            if ($fullweight) {
                $normalizer = (100 - $override_weight) / $contribution;
            } else if ($contribution == 0) {
                $normalizer = 1;
            } else {
                // figure out how much to adjust weights so they are all proporitional to 100
                $normalizer = (100 - $override_weight) / $contribution;
            }

            // go back through and apply normalizer to have weights add up to container
            foreach ($element['children'] as $order => $child) {
                if ($child['object'] instanceof grade_category) {
                    $id = $child['object']->grade_item->id;
                } else {
                    $id = $child['object']->id;
                }

                // get the id of this grade's parent
                if ($child['type'] !== 'course' && $child['type'] !== 'courseitem') {
                    $parent_id = $this->parents[$id]->parent_id; // the parent record contains an id field pointing to its parent, the key on the parent record is the item itself to allow lookup
                }
                if ($child['type'] === 'categoryitem' || $child['type'] === 'courseitem') {
                    continue; // do nothing with these types of elements
                } else if (array_key_exists($id, $this->emptycats)) { // has to be above checkitems
//                	$grades[$elementid]->contrib[$id] = 0;
                   	continue;
                } else if (array_key_exists($id, $checkitems)) {
//                	$grades[$elementid]->contrib[$id] = 0;
//                  	continue; // already dealt with
                } else if ($this->items[$id]->is_hidden() && $this->showtotalsifcontainhidden !== GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN) { // either its not hidden or the hiding setting allows it to be calculated into the total
//                	$grades[$elementid]->contrib[$id] = 0;
                	continue;
                } else if (!isset($grades[$id]->finalgrade) && !$fullweight) { // empty grade no targets
//                	$grades[$elementid]->contrib[$id] = 0;
                   	continue;
                } else if (isset($grades[$id]->weight) && $grades[$id]->weight == -1) { // dropped
                    continue;
                } else {
                    $grades[$id]->weight = $grades[$id]->grade_item->grademax * $normalizer; // TODO: fix this to use cat_max or something
                }
                if ($element['object']->grade_item->itemtype !== 'course') {
                    if (isset($grades[$elementid]->weight)) {
                    	$parent_weight = $grades[$parent_id]->weight * .01;
                    } else {
                    	$parent_weight = 1;
                    }
                    $grades[$elementid]->pctg[$id] = $grades[$id]->finalgrade / $grades[$id]->grade_item->grademax * $grades[$id]->weight; // need to supply the container with the percentage for the item
   		            $grades[$elementid]->contrib[$id] = $grades[$id]->finalgrade / $grades[$id]->grade_item->grademax * $grades[$id]->weight * $parent_weight * .01; // need to supply the container with the percentage for the item
                }
            }
   	       	if (isset($grades[$elementid]->contrib) && is_array($grades[$elementid]->contrib)) {
    	        $this->cat = $this->items[$elementid]->get_item_category(); // need category settings like drop-low or keep-high
        	    $this->limit_item($elementid, $grades, $unset);
  	       	}
            foreach ($element['children'] as $order => $child) {
                if (isset($child['children'])) {
                    $this->calc_weights_recursive2($child, $grades, $unset, $fullweight);
                }
	            if ($element['object']->grade_item->itemtype == 'course' && !$fullweight && !$unset) {
	                if ($child['object'] instanceof grade_category) {
                    	$id = $child['object']->grade_item->id;
   	    	        	if (isset($grades[$id]->contrib)) {
							if (is_array($grades[$id]->contrib)) {
	   	    	        		$grades[$elementid]->contrib[$id] = array_sum($grades[$id]->contrib);
							} else {
								$grades[$elementid]->contrib[$id] = $grades[$id]->finalgrade / $grades[$id]->grade_item->grademax * $grades[$id]->weight; // need to supply the container with the percentage for the item
							}
   	    	        	}
	                } else {
    	                $id = $child['object']->id;
	                    if (isset($grades[$elementid]->weight)) {
        	            	$parent_weight = $grades[$elementid]->weight * .01;
            	        } else {
                	    	$parent_weight = 1;
                    	}
    	                if (isset($grades[$id]->weight) && $grades[$id]->weight !== 0 && $grades[$id]->grade_item->grademax !== 0) {
	                    	$grades[$elementid]->pctg[$id] = $grades[$id]->finalgrade / $grades[$id]->grade_item->grademax * $grades[$id]->weight; // need to supply the container with the percentage for the item
   		    	        	$grades[$elementid]->contrib[$id] = $grades[$id]->finalgrade / $grades[$id]->grade_item->grademax * $grades[$id]->weight * $parent_weight * .01; // need to supply the container with the percentage for the item
    	                }
	                }
	                if (isset($grades[$elementid]->contrib[$elementid])) {
	                	unset($grades[$elementid]->contrib[$elementid]);
	                }
            	}
            }

        }
    }
}

require_once "$CFG->libdir/grade/grade_category.php";

class grade_category_local extends grade_category {


    /**
     * Some aggregation types may automatically update max grade
     *
     * @param array $items sub items
     */
    function auto_update_max($items) {
        if ($this->aggregation != GRADE_AGGREGATE_SUM) {
            // not needed at all
            return;
        }

        if (!$items) {

            if ($this->grade_item->grademax != 0 or $this->grade_item->gradetype != GRADE_TYPE_VALUE) {
                $this->grade_item->grademax  = 0;
                $this->grade_item->grademin  = 0;
                $this->grade_item->gradetype = GRADE_TYPE_VALUE;
                $this->grade_item->update('aggregation');
            }
            return;
        }

        //find max grade possible
        $maxes = array();

        foreach ($items as $item) {

            if ($item->aggregationcoef > 0) {
                // extra credit from this activity - does not affect total
                continue;
            }

            if ($item->gradetype == GRADE_TYPE_VALUE) {
                $maxes[$item->id] = $item->grademax;

            } else if ($item->gradetype == GRADE_TYPE_SCALE) {
                $maxes[$item->id] = $item->grademax; // 0 = nograde, 1 = first scale item, 2 = second scale item
            }
        }
        // apply droplow and keephigh
        $this->apply_limit_rules($maxes, $items);
        $max = array_sum($maxes);

        // update db if anything changed
        if ($this->grade_item->grademax != $max or $this->grade_item->grademin != 0 or $this->grade_item->gradetype != GRADE_TYPE_VALUE) {
            $this->grade_item->grademax  = $max;
            $this->grade_item->grademin  = 0;
            $this->grade_item->gradetype = GRADE_TYPE_VALUE;
            $this->grade_item->update('aggregation');
        }
    }

}
function sumofgradesonly($courseid) {
	global $CFG, $DB;
	require_once "$CFG->dirroot/lib/grade/constants.php";
	require_once "$CFG->dirroot/lib/grade/grade_category.php";
	require_once "$CFG->dirroot/lib/gradelib.php";
	if ($CFG->grade_sumofgradesonly == FORCE_SUM_OF_GRADES) {
		return 'force';
	} else if ($CFG->grade_sumofgradesonly == OPTIONAL_SUM_OF_GRADES) {
		// iterate through all categories to see if they're all Sum of grades
		$cats = $DB->get_records('grade_categories', array('courseid' => $courseid));
		foreach ($cats as $cat) {
			if ($cat->aggregation != GRADE_AGGREGATE_SUM) {
				return false;
			}
		}
		return 'optional';
	} else {
		return false;
	}
}

function arrayclone($array) {
    $return = array();
    foreach ($array as $key => $value) {
        $return[$key] = $value;
    }
    return $return;
}

function is_percentage($gradestr = null) {
    return (substr(trim($gradestr),-1,1) == '%') ? true : false;
}

?>