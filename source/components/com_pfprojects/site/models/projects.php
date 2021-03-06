<?php
/**
 * @package      Projectfork
 * @subpackage   Projects
 *
 * @author       Tobias Kuhn (eaxs)
 * @copyright    Copyright (C) 2006-2012 Tobias Kuhn. All rights reserved.
 * @license      http://www.gnu.org/licenses/gpl.html GNU/GPL, see LICENSE.txt
 */

defined('_JEXEC') or die();


jimport('joomla.application.component.modellist');
jimport('joomla.application.component.helper');


/**
 * This models supports retrieving lists of projects.
 *
 */
class PFprojectsModelProjects extends JModelList
{
    /**
     * Constructor.
     *
     * @param    array          An optional associative array of configuration settings.
     * @see      jcontroller
     */
    public function __construct($config = array())
    {
        // Set field filter
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = array(
                'a.id', 'category_title, a.title',
                'category_title', 'a.created', 'a.modified',
                'a.state', 'a.start_date', 'a.end_date',
                'author_name', 'editor', 'access_level',
                'milestones', 'tasks', 'tasklists'
            );
        }

        parent::__construct($config);
    }


    /**
     * Get the master query for retrieving a list of items subject to the model state.
     *
     * @return    jdatabasequery
     */
    public function getListQuery()
    {
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);
        $user  = JFactory::getUser();

        // Select the required fields from the table.
        $query->select(
            $this->getState('list.select',
                'a.id, a.asset_id, a.catid, a.title, a.alias, a.description, a.created, '
                . 'a.created_by, a.modified, a.modified_by, a.checked_out, '
                . 'a.checked_out_time, a.attribs, a.access, a.state, a.start_date, '
                . 'a.end_date'
            )
        );

        $query->from('#__pf_projects AS a');

        // Join over the users for the checked out user.
        $query->select('uc.name AS editor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the asset groups.
        $query->select('ag.title AS access_level');
        $query->join('LEFT', '#__viewlevels AS ag ON ag.id = a.access');

        // Join over the users for the owner.
        $query->select('ua.name AS author_name, ua.email AS author_email');
        $query->join('LEFT', '#__users AS ua ON ua.id = a.created_by');

        // Join over the milestones for milestone count
        $query->select('COUNT(DISTINCT ma.id) AS milestones');
        $query->join('LEFT', '#__pf_milestones AS ma ON ma.project_id = a.id');

        // Join over the categories.
        $query->select('c.title AS category_title');
        $query->join('LEFT', '#__categories AS c ON c.id = a.catid');

        // Join over the task lists for list count
        $query->select('COUNT(DISTINCT tl.id) AS tasklists');
        $query->join('LEFT', '#__pf_task_lists AS tl ON tl.project_id = a.id');

        // Join over the observer table for email notification status
        if ($user->get('id') > 0) {
            $query->select('COUNT(DISTINCT obs.user_id) AS watching');
            $query->join('LEFT', '#__pf_ref_observer AS obs ON (obs.item_type = '
                  . $db->quote('com_pfprojects.project') . ' AND obs.item_id = a.id AND obs.user_id = '
                  . $db->quote($user->get('id')) . ')'
            );
        }

        // Join over the attachments for attachment count
        $query->select('COUNT(DISTINCT at.id) AS attachments');
        $query->join('LEFT', '#__pf_ref_attachments AS at ON (at.item_type = '
              . $db->quote('com_pfprojects.project') . ' AND at.item_id = a.id)');

        // Join over the comments for comment count
        $query->select('COUNT(DISTINCT co.id) AS comments');
        $query->join('LEFT', '#__pf_comments AS co ON (co.context = '
              . $db->quote('com_pfprojects.project') . ' AND co.item_id = a.id)');

        // Implement View Level Access
        if (!$user->authorise('core.admin', 'com_pfprojects')) {
            $levels = implode(',', $user->getAuthorisedViewLevels());

            $query->where('a.access IN (' . $levels . ')');
        }

        // Filter by a single or group of categories.
        $baselevel = 1;
        $catid = $this->getState('filter.category');
        if (is_numeric($catid)) {
            $cat_tbl = JTable::getInstance('Category', 'JTable');

            if ($cat_tbl) {
                if ($cat_tbl->load($catid)) {
                    $rgt       = $cat_tbl->rgt;
                    $lft       = $cat_tbl->lft;
                    $baselevel = (int) $cat_tbl->level;

                    $query->where('c.lft >= ' . (int) $lft);
                    $query->where('c.rgt <= ' . (int) $rgt);
                }
            }
        }
        elseif (is_array($catid)) {
            JArrayHelper::toInteger($catid);

            $catid = implode(',', $catid);
            $query->where('a.catid IN (' . $catid . ')');
        }

        // Filter fields
        $filters = array();
        $filters['a.state']      = array('STATE',       $this->getState('filter.published'));
        $filters['a.created_by'] = array('INT-NOTZERO', $this->getState('filter.author'));
        $filters['a']            = array('SEARCH',      $this->getState('filter.search'));

        // Apply Filter
        PFQueryHelper::buildFilter($query, $filters);

        // Group by ID
        $query->group('a.id');

        // Add the list ordering clause.
        $query->order($this->getState('list.ordering', 'category_title, a.title') . ' ' . $this->getState('list.direction', 'ASC'));

        return $query;
    }


    /**
     * Method to get a list of items.
     * Overriden to inject convert the attribs field into a JParameter object.
     *
     * @return    mixed    $items    An array of objects on success, false on failure.
     */
    public function getItems()
    {
        if (JDEBUG) {
            JProfiler::getInstance('Application')->mark('onBeforeGetProjects');
        }

        $items     = parent::getItems();
        $base_path = JPATH_ROOT . '/media/com_projectfork/repo/0/logo';
        $base_url  = JURI::root(true) . '/media/com_projectfork/repo/0/logo';

        $tasks_exists = PFApplicationHelper::enabled('com_pftasks');

        $pks = JArrayHelper::getColumn($items, 'id');

        // Get aggregate data
        $progress        = array();
        $total_tasks     = array();
        $completed_tasks = array();

        if ($tasks_exists) {
            JLoader::register('PFtasksModelTasks', JPATH_SITE . '/components/com_pftasks/models/tasks.php');

            $tmodel      = JModelLegacy::getInstance('Tasks', 'PFtasksModel', array('ignore_request' => true));
            $progress    = $tmodel->getAggregatedProgress($pks, 'project_id');
            $total_tasks = $tmodel->getAggregatedTotal($pks, 'project_id');
            $completed_tasks = $tmodel->getAggregatedTotal($pks, 'project_id', 1);
        }

        // Loop over each row to inject data
        foreach ($items as $i => &$item)
        {
            $params = new JRegistry;
            $params->loadString($item->attribs);

            // Convert the parameter fields into objects.
            $items[$i]->params = clone $this->getState('params');

            // Create slug
            $items[$i]->slug = $items[$i]->alias ? ($items[$i]->id . ':' . $items[$i]->alias) : $items[$i]->id;

            // Try to find the logo img
            $items[$i]->logo_img = null;

            if (JFile::exists($base_path . '/' . $item->id . '.jpg')) {
                $items[$i]->logo_img = $base_url . '/' . $item->id . '.jpg';
            }
            elseif (JFile::exists($base_path . '/' . $item->id . '.jpeg')) {
                $items[$i]->logo_img = $base_url . '/' . $item->id . '.jpeg';
            }
            elseif (JFile::exists($base_path . '/' . $item->id . '.png')) {
                $items[$i]->logo_img = $base_url . '/' . $item->id . '.png';
            }
            elseif (JFile::exists($base_path . '/' . $item->id . '.gif')) {
                $items[$i]->logo_img = $base_url . '/' . $item->id . '.gif';
            }

            // Inject task count
            $items[$i]->tasks = (isset($total_tasks[$item->id]) ? $total_tasks[$item->id] : 0);

            // Inject completed task count
            $items[$i]->completed_tasks = (isset($completed_tasks[$item->id]) ? $completed_tasks[$item->id] : 0);

            // Inject progress
            $items[$i]->progress = (isset($progress[$item->id]) ? $progress[$item->id] : 0);
        }

        if (JDEBUG) {
            JProfiler::getInstance('Application')->mark('onAfterGetProjects');
        }

        return $items;
    }


    /**
     * Method to auto-populate the model state.
     * Note. Calling getState in this method will result in recursion.
     *
     * @return    void
     */
    protected function populateState($ordering = 'category_title, a.title', $direction = 'ASC')
    {
        $app = JFactory::getApplication();

        // Adjust the context to support modal layouts.
        $layout = JRequest::getCmd('layout');

        // View Layout
        $this->setState('layout', $layout);
        if ($layout) $this->context .= '.' . $layout;

        // Params
        $value = $app->getParams();
        $this->setState('params', $value);

        // State
        $state = $app->getUserStateFromRequest($this->context . '.filter.published', 'filter_published', '');
        $this->setState('filter.published', $state);

        // Filter on published for those who do not have edit or edit.state rights.
        $access = PFprojectsHelper::getActions();
        if (!$access->get('core.edit.state') && !$access->get('core.edit')) {
            $this->setState('filter.published', 1);
            $state = '';
        }

        // Filter - Search
        $search = JRequest::getString('filter_search', '');
        $this->setState('filter.search', $search);

        // Filter - Author
        $author = $app->getUserStateFromRequest($this->context . '.filter.author', 'filter_author', '');
        $this->setState('filter.author', $author);

        // Filter - Category
        $cat = $app->getUserStateFromRequest($this->context . '.filter.category', 'filter_category', '');
        $this->setState('filter.category', $cat);

        // Filter - Is set
        $this->setState('filter.isset', (is_numeric($state) || !empty($search) || is_numeric($author) || is_numeric($cat)));

        // Call parent method
        parent::populateState($ordering, $direction);
    }


    /**
     * Method to get a store id based on model configuration state.
     *
     * This is necessary because the model is used by the component and
     * different modules that might need different sets of data or different
     * ordering requirements.
     *
     * @param     string    $id    A prefix for the store id.
     *
     * @return    string           A store id.
     */
    protected function getStoreId($id = '')
    {
        // Compile the store id
        $id .= ':' . $this->getState('filter.published');
        $id .= ':' . $this->getState('filter.author');
        $id .= ':' . $this->getState('filter.category');
        $id .= ':' . $this->getState('filter.search');

        return parent::getStoreId($id);
    }
}
