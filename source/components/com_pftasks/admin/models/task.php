<?php
/**
 * @package      Projectfork
 * @subpackage   Tasks
 *
 * @author       Tobias Kuhn (eaxs)
 * @copyright    Copyright (C) 2006-2012 Tobias Kuhn. All rights reserved.
 * @license      http://www.gnu.org/licenses/gpl.html GNU/GPL, see LICENSE.txt
 */

defined('_JEXEC') or die();


jimport('joomla.application.component.modeladmin');


/**
 * Item Model for a task form.
 *
 */
class PFtasksModelTask extends JModelAdmin
{
    /**
     * The prefix to use with controller messages.
     *
     * @var    string
     */
    protected $text_prefix = 'COM_PROJECTFORK_TASK';


    /**
     * Constructor.
     *
     * @param    array          $config    An optional associative array of configuration settings.
     *
     * @see      jcontroller
     */
    public function __construct($config = array())
    {
        parent::__construct($config);
    }


    /**
     * Returns a Table object, always creating it.
     *
     * @param     string    The table type to instantiate
     * @param     string    A prefix for the table class name. Optional.
     * @param     array     Configuration array for model. Optional.
     *
     * @return    jtable    A database object
     */
    public function getTable($type = 'Task', $prefix = 'PFtable', $config = array())
    {
        return JTable::getInstance($type, $prefix, $config);
    }


    /**
     * Method to get a single record.
     *
     * @param     integer    The id of the primary key.
     * @return    mixed      Object on success, false on failure.
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            // Convert the params field to an array.
            $registry = new JRegistry;
            $registry->loadString($item->attribs);
            $item->attribs = $registry->toArray();

            $item->users = $this->getUsers($pk);

            // Convert seconds back to minutes
            if ($item->estimate > 0) {
                $item->estimate = round($item->estimate / 60);
            }

            // Get the attachments
            $attachments = $this->getInstance('Attachments', 'PFrepoModel');
            $item->attachment = $attachments->getItems('task', $item->id);

            // Get the labels
            $labels = $this->getInstance('Labels', 'PFModel');
            $item->labels = $labels->getConnections('task', $item->id);

            // Get the dependencies
            $taskrefs = $this->getInstance('TaskRefs', 'PFtasksModel');
            $item->dependency = $taskrefs->getItems($item->id, true);
        }

        return $item;
    }


    /**
     * Method to get assigned users of a task
     *
     * @param     integer    The id of the primary key.
     * @return    array      The assigned users
     */
    public function getUsers($pk = NULL)
    {
        if (!$pk) $pk = $this->getState($this->getName() . '.id');
        if (!$pk) return array();

        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);

        $query->select('user_id')
              ->from('#__pf_ref_users')
              ->where('item_type = ' . $db->quote('task'))
              ->where('item_id = ' . $db->quote($pk));

        $db->setQuery((string) $query);
        $data = (array) $db->loadResultArray();
        $list = array();

        foreach($data AS $i => $uid)
        {
            $list['user' . $i] = $uid;
        }

        return $list;
    }


    /**
     * Method to get the record form.
     *
     * @param     array      Data for the form.
     * @param     boolean    True if the form is to load its own data (default case), false if not.
     * @return    mixed      A JForm object on success, false on failure
     */
    public function getForm($data = array(), $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm('com_pftasks.task', 'task', array('control' => 'jform', 'load_data' => $loadData));
        if (empty($form)) return false;

        $is_new    = ((int) $this->getState($this->getName() . '.id') > 0) ? false : true;
        $project   = (int) $form->getValue('project_id');
        $milestone = (int) $form->getValue('milestone_id');
        $list      = (int) $form->getValue('list_id');

        // Override data if not set
        if ($is_new) {
            if ($project == 0) {
                $active_id = PFApplicationHelper::getActiveProjectId();

                $form->setValue('project_id', null, $active_id);
            }

            // Override milestone selection if set
            if ($milestone == 0) {
                $form->setValue('milestone_id', null, JRequest::getUInt('milestone_id'));
            }

            // Override task list selection if set
            if ($list == 0) {
                $form->setValue('list_id', null, JRequest::getUInt('list_id'));
            }
        }

        return $form;
    }


    /**
     * Method to get the data that should be injected in the form.
     *
     * @return    mixed    The data for the form.
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = JFactory::getApplication()->getUserState('com_pftasks.edit.' . $this->getName() . '.data', array());

        if (empty($data)) $data = $this->getItem();

        return $data;
    }


    /**
     * A protected method to get a set of ordering conditions.
     *
     * @param     object    A record object.
     *
     * @return    array     An array of conditions to add to add to ordering queries.
     */
    protected function getReorderConditions($table)
    {
        $catid = intval($table->project_id) . '-' . intval($table->milestone_id) . '-' . intval($table->list_id);

        $condition = array();
        $condition[] = 'catid = '.(int) $catid;

        return $condition;
    }


    /**
     * Prepare and sanitise the table data prior to saving.
     *
     * @param     jtable    A JTable object.
     *
     * @return    void
     */
    protected function prepareTable(&$table)
    {
        // Generate catid
        $catid = intval($table->project_id) . '-' . intval($table->milestone_id) . '-' . intval($table->list_id);

        // Reorder the items within the category so the new item is first
        if (empty($table->id)) {
            $table->reorder('catid = '.(int) $catid.' AND state >= 0');
        }
    }


    /**
     * Method to save the form data.
     *
     * @param     array      The form data
     *
     * @return    boolean    True on success
     */
    public function save($data)
    {
        $table  = $this->getTable();
        $key    = $table->getKeyName();
        $pk     = (!empty($data[$key])) ? $data[$key] : (int) $this->getState($this->getName() . '.id');
        $is_new = true;

        // Include the content plugins for the on save events.
        JPluginHelper::importPlugin('content');
        $dispatcher = JDispatcher::getInstance();

        try {
            if ($pk > 0) {
                if ($table->load($pk)) {
                    $is_new = false;
                }
            }

            // Make sure the title and alias are always unique
            $data['alias'] = '';
            list($title, $alias) = $this->generateNewTitle($data['title'], $data['project_id'], $data['milestone_id'], $data['list_id'], $data['alias'], $pk);

            $data['title'] = $title;
            $data['alias'] = $alias;

            // Handle permissions and access level
            if (isset($data['rules'])) {
                $access = PFAccessHelper::getViewLevelFromRules($data['rules'], intval($data['access']));

                if ($access) {
                    $data['access'] = $access;
                }
            }
            else {
                if ($is_new) {
                    $data['access'] = 1;
                }
                else {
                    if (isset($data['access'])) {
                        unset($data['access']);
                    }
                }
            }

            // Try to convert estimate string to time
            if (isset($data['estimate'])) {
                if (!is_numeric($data['estimate'])) {
                    $estimate_time = strtotime($data['estimate']);

                    if ($estimate_time === false || $estimate_time < 0) {
                        $data['estimate'] = 1;
                    }
                    else {
                        $data['estimate'] = $estimate_time - time();
                    }
                }
                else {
                    // not a literal time, so convert minutes to secs
                    $data['estimate'] = $data['estimate'] * 60;
                }
            }

            // Bind the data.
            if (!$table->bind($data)) {
                $this->setError($table->getError());
                return false;
            }

            // Prepare the row for saving
            $this->prepareTable($table);

            // Check the data.
            if (!$table->check()) {
                $this->setError($table->getError());
                return false;
            }

            // Trigger the onContentBeforeSave event.
            $result = $dispatcher->trigger($this->event_before_save, array($this->option . '.' . $this->name, &$table, $is_new));

            if (in_array(false, $result, true)) {
                $this->setError($table->getError());
                return false;
            }

            // Store the data.
            if (!$table->store()) {
                $this->setError($table->getError());
                return false;
            }

            $pk_name = $table->getKeyName();

            if (isset($table->$pk_name)) {
                $this->setState($this->getName() . '.id', $table->$pk_name);
            }

            $this->setState($this->getName() . '.new', $is_new);

            $id = $this->getState($this->getName() . '.id');

            // Load the just updated row
            $updated = $this->getTable();
            if ($updated->load($id) === false) return false;

            // Set the active project
            PFApplicationHelper::setActiveProject($updated->project_id);

            // Add to watch list
            if ($is_new) {
                $cid = array($id);

                if (!$this->watch($cid, 1)) {
                    return false;
                }
            }

            // Store the attachments
            if (isset($data['attachment'])) {
                $attachments = $this->getInstance('Attachments', 'PFrepoModel');

                if ($attachments->getState('item.id') == 0) {
                    $attachments->setState('item.id', $this->getState($this->getName() . '.id'));
                }

                if (!$attachments->save($data['attachment'])) {
                    $this->setError($attachments->getError());
                    return false;
                }
            }

            // Store the labels
            if (isset($data['labels'])) {
                $labels = $this->getInstance('Labels', 'PFModel');

                if ((int) $labels->getState('item.project') == 0) {
                    $labels->setState('item.project', $updated->project_id);
                }

                $labels->setState('item.type', 'task');
                $labels->setState('item.id', $id);

                if (!$labels->saveRefs($data['labels'])) {
                    return false;
                }
            }

            // Store the dependencies
            if (isset($data['dependency'])) {
                $taskrefs = $this->getInstance('TaskRefs', 'PFtasksModel');

                if ((int) $taskrefs->getState('item.project') == 0) {
                    $taskrefs->setState('item.project', $updated->project_id);
                }

                $taskrefs->setState('item.id', $id);

                if (!$taskrefs->save($data['dependency'])) {
                    return false;
                }
            }

            // Clean the cache.
            $this->cleanCache();

            // Trigger the onContentAfterSave event.
            $dispatcher->trigger($this->event_after_save, array($this->option . '.' . $this->name, &$table, $is_new));
        }
        catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        return true;
    }


    /**
     * Method to watch an item
     *
     * @param    array      $pks      The items to watch
     * @param    integer    $value    1 to watch, 0 to unwatch
     * @param    integer    $uid      The user id to watch the item
     */
    public function watch(&$pks, $value = 1, $uid = null)
    {
        $user  = JFactory::getUser($uid);
        $table = $this->getTable();
        $pks   = (array) $pks;

        $is_admin = $user->authorise('core.admin', $this->option);
        $my_views = $user->getAuthorisedViewLevels();
        $projects = array();

        // Access checks.
        foreach ($pks as $i => $pk) {
            $table->reset();

            if ($table->load($pk)) {
                if (!$is_admin && !in_array($table->access, $my_views)) {
                    unset($pks[$i]);
                    JError::raiseWarning(403, JText::_('JERROR_ALERTNOAUTHOR'));
                    $this->setError(JText::_('JERROR_ALERTNOAUTHOR'));
                    return false;
                }

                $projects[$pk] = (int) $table->project_id;
            }
            else {
                unset($pks[$i]);
            }
        }

        // Attempt to watch/unwatch the selected items
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);

        foreach ($pks AS $i => $pk)
        {
            $query->clear();

            if ($value == 0) {
                $query->delete('#__pf_ref_observer')
                      ->where('item_type = ' . $db->quote( str_replace('form', '', $this->getName()) ) )
                      ->where('item_id = ' . $db->quote((int) $pk))
                      ->where('user_id = ' . $db->quote((int) $user->get('id')));

                $db->setQuery($query);
                $db->execute();

                if ($db->getError()) {
                    $this->setError($db->getError());
                    return false;
                }
            }
            else {
                $query->select('COUNT(*)')
                      ->from('#__pf_ref_observer')
                      ->where('item_type = ' . $db->quote( str_replace('form', '', $this->getName()) ) )
                      ->where('item_id = ' . $db->quote((int) $pk))
                      ->where('user_id = ' . $db->quote((int) $user->get('id')));

                $db->setQuery($query);
                $count = (int) $db->loadResult();

                if (!$count) {
                    $data = new stdClass;

                    $data->user_id   = (int) $user->get('id');
                    $data->item_type = str_replace('form', '', $this->getName());
                    $data->item_id   = (int) $pk;
                    $data->project_id= (int) $projects[$pk];

                    $db->insertObject('#__pf_ref_observer', $data);

                    if ($db->getError()) {
                        $this->setError($db->getError());
                        return false;
                    }
                }
            }
        }

        // Clear the component's cache
        $this->cleanCache();

        return true;
    }


    /**
     * Custom clean the cache of com_projectfork and projectfork modules
     *
     */
    protected function cleanCache($group = null, $client_id = 0)
    {
        parent::cleanCache('com_pftasks');
    }


    /**
     * Method to change the title & alias.
     * Overloaded from JModelAdmin class
     *
     * @param     string     $title      The title
     * @param     integer    $project    The project id
     * @param     integer    $milestone    The milestone id
     * @param     integer    $list    The list id
     * @param     string     $alias      The alias
     * @param     integer    $id         The item id
     *
     *
     * @return    array                  Contains the modified title and alias
     */
    protected function generateNewTitle($title, $project, $milestone = 0, $list = 0, $alias = '', $id = 0)
    {
        $table = $this->getTable();
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);

        if (empty($alias)) {
            $alias = JApplication::stringURLSafe($title);

            if (trim(str_replace('-', '', $alias)) == '') {
                $alias = JApplication::stringURLSafe(JFactory::getDate()->format('Y-m-d-H-i-s'));
            }
        }

        $query->select('COUNT(id)')
              ->from($table->getTableName())
              ->where('alias = ' . $db->quote($alias))
              ->where('project_id = ' . $db->quote((int) $project))
              ->where('milestone_id = ' . $db->quote((int) $milestone))
              ->where('list_id = ' . $db->quote((int) $list));

        if ($id) {
            $query->where('id != ' . intval($id));
        }

        $db->setQuery((string) $query);
        $count = (int) $db->loadResult();

        if ($id > 0 && $count == 0) {
            return array($title, $alias);
        }
        elseif ($id == 0 && $count == 0) {
            return array($title, $alias);
        }
        else {
            while ($table->load(array('alias' => $alias, 'project_id' => $project, 'milestone_id' => $milestone, 'list_id' => $list)))
            {
                $m = null;

                if (preg_match('#-(\d+)$#', $alias, $m)) {
                    $alias = preg_replace('#-(\d+)$#', '-'.($m[1] + 1).'', $alias);
                }
                else {
                    $alias .= '-2';
                }

                if (preg_match('#\((\d+)\)$#', $title, $m)) {
                    $title = preg_replace('#\(\d+\)$#', '('.($m[1] + 1).')', $title);
                }
                else {
                    $title .= ' (2)';
                }
            }
        }

        return array($title, $alias);
    }


    /**
     * Method to test whether a record can be deleted.
     * Defaults to the permission set in the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to delete the record.
     */
    protected function canDelete($record)
    {
        if (!empty($record->id)) {
            if ($record->state != -2) return false;

            $user  = JFactory::getUser();
            $asset = 'com_pftasks.task.' . (int) $record->id;

            return $user->authorise('core.delete', $asset);
        }

        return parent::canDelete($record);
    }


    /**
     * Method to test whether a record can have its state edited.
     * Defaults to the permission set in the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to delete the record.
     */
    protected function canEditState($record)
    {
        $user = JFactory::getUser();

		// Check for existing item.
		if (!empty($record->id)) {
			return $user->authorise('core.edit.state', 'com_pftasks.task.' . (int) $record->id);
		}
        elseif (!empty($record->list_id)) {
		    // New item, so check against the list.
			return $user->authorise('core.edit.state', 'com_pftasklists.tasklist.' . (int) $record->list_id);
		}
        elseif (!empty($record->milestone_id)) {
		    // New item, so check against the milestone.
			return $user->authorise('core.edit.state', 'com_pfmilestones.milestone.' . (int) $record->milestone_id);
		}
		elseif (!empty($record->project_id)) {
		    // New item, so check against the project.
			return $user->authorise('core.edit.state', 'com_pfprojects.project.' . (int) $record->project_id);
		}
		else {
		    // Default to component settings if neither article nor category known.
			return parent::canEditState('com_pftasks');
		}
    }


    /**
     * Method to test whether a record can be edited.
     * Defaults to the permission for the component.
     *
     * @param     object     A record object.
     *
     * @return    boolean    True if allowed to edit the record.
     */
    protected function canEdit($record)
    {
        $user = JFactory::getUser();

        // Check for existing item.
        if (!empty($record->id)) {
            $asset = 'com_pftasks.task.' . (int) $record->id;

            return ($user->authorise('core.edit', $asset) || ($access->get('core.edit.own', $asset) && $record->created_by == $user->id));
        }

        return $user->authorise('core.edit', 'com_pftasks');
    }
}