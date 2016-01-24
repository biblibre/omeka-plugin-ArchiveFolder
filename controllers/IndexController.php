<?php
/**
 * Controller for Achive Folder admin pages.
 *
 * @package ArchiveFolder
 */
class ArchiveFolder_IndexController extends Omeka_Controller_AbstractActionController
{
    /**
     * The number of records to browse per page.
     *
     * @var string
     */
    protected $_browseRecordsPerPage = 100;

    protected $_autoCsrfProtection = true;

    /**
     * Controller-wide initialization. Sets the underlying model to use.
     */
    public function init()
    {
        $this->_db = $this->_helper->db;
        $this->_db->setDefaultModelName('ArchiveFolder_Folder');
    }

    /**
     * Retrieve and render a set of records for the controller's model.
     *
     * @uses Omeka_Controller_Action_Helper_Db::getDefaultModelName()
     * @uses Omeka_Db_Table::findBy()
     */
    public function browseAction()
    {
        if (!$this->_hasParam('sort_field')) {
            $this->_setParam('sort_field', 'modified');
        }

        if (!$this->_hasParam('sort_dir')) {
            $this->_setParam('sort_dir', 'd');
        }

        parent::browseAction();
    }

    public function addAction()
    {
        require dirname(__FILE__)
            . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'forms'
            . DIRECTORY_SEPARATOR . 'Add.php';

        $this->view->form = new ArchiveFolder_Form_Add();
        $this->view->form->setAction($this->_helper->url('add'));

        // From parent::addAction(), to allow to set parameters as array.
        $class = $this->_helper->db->getDefaultModelName();
        $varName = $this->view->singularize($class);

        if ($this->_autoCsrfProtection) {
            $csrf = new Omeka_Form_SessionCsrf;
            $this->view->csrf = $csrf;
        }

        $record = new $class();
        if ($this->getRequest()->isPost()) {
            if ($this->_autoCsrfProtection && !$csrf->isValid($_POST)) {
                $this->_helper->_flashMessenger(__('There was an error on the form. Please try again.'), 'error');
                $this->view->$varName = $record;
                return;
            }
            $record->setPostData($_POST);

            // Specific is here.
            $parameters = $this->_getParameters($record);
            $record->prepareParameters($parameters);

            if ($record->save(false)) {
                $successMessage = $this->_getAddSuccessMessage($record);
                if ($successMessage != '') {
                    $this->_helper->flashMessenger($successMessage, 'success');
                }
                // Save the identifier field.
                set_option('archive_folder_identifier_field', $parameters['identifier_field']);
                $this->_redirectAfterAdd($record);
            } else {
                $this->_helper->flashMessenger($record->getErrors());
            }
        }
        $this->view->$varName = $record;
    }

    /**
     * Clean the parameters to save from the form.
     *
     * @param Record $record The record that is currently prepared.
     * @return array
     */
    protected function _getParameters($record)
    {
        // Specific is here.
        $parameters = array_flip(array(
            'unreferenced_files',
            'exclude_extensions',
            'element_delimiter',
            'fill_ocr_text',
            'fill_ocr_data',
            'fill_ocr_process',
            'records_for_files',
            // The item_type_id is in _postData().
            'item_type_name',
            'identifier_field',
            'action',
        ));
        foreach ($parameters as $parameter => &$value) {
            $value = isset($record->$parameter) ? $record->$parameter : null;
        }
        return $parameters;
    }

    public function stopAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->_helper->redirector->goto('browse');
        }

        $folder->setStatus(ArchiveFolder_Folder::STATUS_STOPPED);
        $message = __('Process has been stopped.');
        $folder->addMessage($message);

        $this->_helper->redirector->goto('browse');
    }

    public function resetStatusAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->_helper->redirector->goto('browse');
        }

        $folder->setStatus(ArchiveFolder_Folder::STATUS_RESET);
        $folder->save();

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Check change in a folder.
     */
    public function checkAction()
    {
        $result = $this->_launchJob(ArchiveFolder_Builder::TYPE_CHECK);
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Update a folder.
     *
     * Currently, a first process is the same than update (no token or check).
     */
    public function updateAction()
    {
        $result = $this->_launchJob(ArchiveFolder_Builder::TYPE_UPDATE);
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Batch editing of records.
     *
     * @return void
     */
    public function batchEditAction()
    {
        $folderIds = $this->_getParam('folders');
        if (empty($folderIds)) {
            $this->_helper->flashMessenger(__('You must choose some folders to batch edit.'), 'error');
        }
        // Normal process.
        else {
            // Action can be Check (default) or Update.
            $action = $this->getParam('submit-batch-update')
                ? ArchiveFolder_Builder::TYPE_UPDATE
                : ArchiveFolder_Builder::TYPE_CHECK;

            foreach ($folderIds as $folderId) {
                $result = $this->_launchJob(ArchiveFolder_Builder::TYPE_UPDATE, $folderId);
            }
        }

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Launch a process on a record.
     *
     * @param string $processType
     * @param integer $recordId
     * @return boolean Success or failure.
     */
    protected function _launchJob($processType = null, $recordId = 0)
    {
        $id = (integer) ($recordId ?: $this->_getParam('id'));

        $folder = $this->_db->find($id);
        if (empty($folder)) {
            $message = __('Folder # %d does not exist.', $id);
            $this->_helper->flashMessenger($message, 'error');
            return false;
        }

        if (in_array($folder->status, array(
                ArchiveFolder_Folder::STATUS_QUEUED,
                ArchiveFolder_Folder::STATUS_PROGRESS,
            ))) {
            return true;
        }

        $folder->setStatus(ArchiveFolder_Folder::STATUS_QUEUED);
        $folder->save();

        $options = array(
            'folderId' => $folder->id,
            'processType' => $processType,
        );

        $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
        $jobDispatcher->setQueueName(ArchiveFolder_UpdateJob::QUEUE_NAME);

        // Short dispatcher if user wants it.
        if (get_option('archive_folder_short_dispatcher')) {
            try {
                $jobDispatcher->send('ArchiveFolder_UpdateJob', $options);
            } catch (Exception $e) {
                $message = __('Error when processing folder.');
                $folder->setStatus(ArchiveFolder_Folder::STATUS_ERROR);
                $folder->addMessage($message, ArchiveFolder_Folder::MESSAGE_CODE_ERROR);
                _log('[ArchiveFolder] ' . __('Folder "%s" (#%d): %s',
                    $folder->uri, $folder->id, $message), Zend_Log::ERR);
                $this->_helper->flashMessenger($message, 'error');
                return false;
            }

            $message = __('Folder "%s" has been updated.', $folder->uri);
            $this->_helper->flashMessenger($message, 'success');
            return true;
        }

        // Normal dispatcher for long processes.
        $jobDispatcher->sendLongRunning('ArchiveFolder_UpdateJob', $options);
        $message = __('Folder "%s" is being updated.', $folder->uri)
            . ' ' . __('This may take a while. Please check below for status.');
        $this->_helper->flashMessenger($message, 'success');
        return true;
    }

    protected function _getDeleteConfirmMessage($record)
    {
        return __('When a folder is removed, the static repository file and the imported collections, items and files are not deleted from Omeka.');
    }

    /**
     * Return the number of records to display per page.
     *
     * @return integer|null
     */
    protected function _getBrowseRecordsPerPage($pluralName = null)
    {
        return $this->_browseRecordsPerPage;
    }
}
