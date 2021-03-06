<?php
/**
 * This file is part of oTranCe http://www.oTranCe.de
 *
 * @package         oTranCe
 * @subpackage      Controllers
 * @version         SVN: $Rev$
 * @author          $Author$
 */

/**
 * Entries Controller
 *
 * @package         oTranCe
 * @subpackage      Controllers
 */
class EntriesController extends OtranceController
{
    /**
     * @var Application_Model_LanguageEntries
     */
    private $_entriesModel;

    /**
     * @var Application_Model_Languages
     */
    private $_languagesModel;

    /**
     * @var array
     */
    private $_languagesEdit = array();

    /**
     * @var array
     */
    private $_showLanguages = array();

    /**
     * @var array
     */
    private $_referenceLanguages = array();

    /**
     * Init
     *
     * @return void
     */
    public function init()
    {
        $this->_entriesModel   = new Application_Model_LanguageEntries();
        $this->view->user      = $this->_userModel;
        $this->_languagesModel = new Application_Model_Languages();
    }

    /**
     * Handle index action
     *
     * @return void
     */
    public function indexAction()
    {
        if ($this->_request->isPost() && $this->_request->getParam('forwarded') == null) {
            if ($this->_request->getParam('addVar') !== null) {
                $this->_forward('add-variable');
            }
        }
        $this->_initParams();
        $this->_assignVars();
        $this->view->selRecordsPerPage = Msd_Html::getHtmlRangeOptions(
            10,
            200,
            10,
            $this->_dynamicConfig->getParam('entries.recordsPerPage', 10)
        );
        $this->setLanguages();
        $filterLanguageArray     = $this->_languagesModel->getAllLanguages();
        $this->view->selLanguage = Msd_Html::getHtmlOptionsFromAssocArray(
            $filterLanguageArray,
            'id',
            '{name} ({locale})',
            $this->_dynamicConfig->getParam('entries.getUntranslated'),
            false
        );
        // assign file template filter
        $fileTemplateFilter             = $this->_dynamicConfig->getParam('entries.fileTemplateFilter');
        $this->view->fileTemplateFilter = $fileTemplateFilter;

        if ($this->view->getUntranslated == 0) {
            if ($this->view->filterValues > '') {
                $this->view->hits = $this->_entriesModel->getEntriesByValue(
                    $this->view->showLanguages,
                    $this->view->filterValues,
                    $this->view->offset,
                    $this->view->recordsPerPage,
                    $this->view->fileTemplateFilter
                );
            } else {
                $this->view->hits = $this->_entriesModel->getEntriesByKey(
                    $this->view->filterKeys,
                    $this->view->offset,
                    $this->view->recordsPerPage,
                    $this->view->fileTemplateFilter
                );
            }
        } else {
            $languageId = $this->_dynamicConfig->getParam('entries.getUntranslated');
            $this->view->hits
                        = $this->_entriesModel->getUntranslated(
                $languageId,
                $this->view->filter,
                $this->view->offset,
                $this->view->recordsPerPage,
                $this->view->fileTemplateFilter
            );
            // if filtered language is not in edit or reference languages add it to the output
            if (!in_array($languageId, $this->view->showLanguages)) {
                $this->view->showLanguages[] = $languageId;
                sort($this->view->showLanguages);
            }
        }

        $this->view->rows = $this->_entriesModel->getRowCount();
        $this->view->hits = $this->_entriesModel->assignTranslations(
            $this->view->showLanguages,
            $this->view->hits
        );


        foreach ($this->view->hits as $k => $hit) {
            $key = $this->_entriesModel->getKeyById($hit['id']);
            $this->view->hits[$k]['ind'] = $key['ind'];
        }
    }

    /**
     * Get and set language params in view and in private properties
     * (Languages to edit, references and which to show in list view)
     *
     * @return void
     */
    public function setLanguages()
    {
        $this->view->languages     = $this->_languagesModel->getAllLanguages();
        $this->_languagesEdit      = $this->_userModel->getUserLanguageRights();
        $this->view->languagesEdit = $this->_languagesEdit;

        // set reference languages
        $this->_referenceLanguages = $this->_userModel->getRefLanguages();
        $projectSettings           = $this->_config->getParam('project');
        if (isset($projectSettings['forceFallbackAsReference']) && $projectSettings['forceFallbackAsReference']) {
            // add main language as reference
            $this->_referenceLanguages = array_merge(
                array($this->_languagesModel->getFallbackLanguageId()),
                $this->_referenceLanguages
            );
            // in case user has set main language as reference - make each langId unique and show only once
            $this->_referenceLanguages = array_unique($this->_referenceLanguages);
        }
        $this->view->referenceLanguages = $this->_referenceLanguages;

        // build show language array for index page
        $this->_showLanguages      = $this->_languagesEdit;
        $this->_showLanguages      = array_merge($this->_showLanguages, $this->_referenceLanguages);
        $this->_showLanguages      = array_unique($this->_showLanguages);
        $this->view->showLanguages = $this->_showLanguages;
    }

    /**
     * Handle edit action
     *
     * @return void
     */
    public function editAction()
    {
        $keyId = $this->_request->getParam('id');
        if ($this->_request->isPost() && $this->_request->getParam('forwarded') == null) {
            $saveEntry = true;
            // check if the file template has changed
            $entry           = $this->_entriesModel->getKeyById($keyId);
            $newFileTemplate = (int)$this->_request->getParam('fileTemplate');
            if ((int)$entry['template_id'] !== $newFileTemplate) {
                // template changed - look up if there already is a key with that name
                if (!$this->_entriesModel->validateLanguageKey($entry['key'], $newFileTemplate)) {
                    $this->view->keyExistsError = implode('<br>', $this->_entriesModel->getValidateMessages());
                    $this->_request->setParam('fileTemplate', $entry['template_id']);
                    $saveEntry = false;
                }
            }

            if ($saveEntry === true) {
                $ignoreSmallChange      = $this->_request->getParam('only-small-change') != null;
                $saved                  = $this->_saveEntries($ignoreSmallChange);
                $this->view->entrySaved = $saved;

                if ($saved && $this->_request->getParam('saveGetUntranslated', null) !== null) {
                    // user pressed button "get next untranslated" and no error occuired while saving
                    $nextKeyId = $this->_findNextUntranslatedKey();
                    if ($nextKeyId !== false) {
                        $keyId = $nextKeyId;
                    }
                }
            }
        }
        $this->setLanguages();
        $this->view->langStatus         = $this->_getLanguagesStatus();
        $this->view->key                = $this->_entriesModel->getKeyById($keyId);
        $this->view->entry              = $this->_entriesModel->getTranslationsByKeyId($keyId, $this->_showLanguages);
        $this->view->needsUpdate        = $this->_entriesModel->getNeedsUpdateStatusByKeyId($keyId);
        $this->view->fallbackLanguageId = $this->_languagesModel->getFallbackLanguageId();
        $this->view->user               = $this->_userModel;

        $templatesModel                   = new Application_Model_FileTemplates();
        $this->view->fileTemplates        = $templatesModel->getFileTemplates('name');
        $this->view->assignedFileTemplate = $this->_entriesModel->getAssignedFileTemplate($keyId);

        // set languages that are translatable via external service if using service is activated
        $translationConfig        = $this->_config->getParam('translationService', array());
        $translationServiceActive = isset($translationConfig['useService']) ? $translationConfig['useService'] : false;
        if ($translationServiceActive) {
            $translationService       = Msd_Translate::getInstance($translationConfig['selectedService']);
            $this->view->translatable = array_keys($translationService->getLocaleMap());
        }
        $this->view->useTranslationService  = $translationServiceActive;
        $this->view->translationServiceName = (isset($translationConfig['selectedService']) ? $translationConfig['selectedService'] : null);

        $this->view->skipKeysOffsets = $this->_dynamicConfig->getParam('entries.skippedKeys', array());
        $this->_setReferrer();


    }

    /**
     * Detect referrer and set it to view.
     * Used to send the user back to the connector if he hits "back to overview" after he clicked edit
     * in the connector list.
     *
     * @return void
     */
    protected function _setReferrer()
    {
        $referrer = 'entries';
        if ($this->_request->getParam('ref', false)) {
            $referrer = $this->_request->getParam('ref');
            // Security: check against white list
            if (!in_array($referrer, array('connector'))) {
                $referrer = 'entries';
            }
        }
        $this->view->referrer = $referrer;
    }

    /**
     * Find the next untranslated key. Iterates over all languages the user can edit.
     *
     * @return bool|int
     */
    private function _findNextUntranslatedKey()
    {
        $nextKeyId  = null;
        $langStatus = $this->_getLanguagesStatus();
        foreach ($langStatus as $languageId => $data) {
            $skippedKeys = $this->_getLanguageKeyOffset($languageId);
            if ($data['notTranslated'] > 0) {
                if ($skippedKeys[$languageId] > $data['notTranslated'] - 1) {
                    $skippedKeys[$languageId] = $data['notTranslated'] - 1;
                }
                // check for next key including the "skipped" offset
                $nextKeyId = $this->_findNextUntranslated($languageId, $skippedKeys[$languageId]);
                if ($nextKeyId === null) {
                    // nothing found - try with resetting the offset to 0
                    $nextKeyId                = $this->_findNextUntranslated($languageId, 0);
                    $skippedKeys[$languageId] = 0;
                }
                if ($nextKeyId !== null) {
                    $this->view->setFocusToLanguage = $languageId;
                    break;
                }
            }
        }
        // save corrected offsets
        $this->_dynamicConfig->setParam('entries.skippedKeys', $skippedKeys);

        return $nextKeyId === null ? false : $nextKeyId;
    }

    /**
     * Get status info for languages the user is allowed to edit including the number of untranslated keys.
     *
     * @return array
     */
    private function _getLanguagesStatus()
    {
        $editLanguages = $this->_userModel->getUserLanguageRights();
        $getStatus     = array();
        foreach ($editLanguages as $languageId) {
            $getStatus[]['id'] = $languageId;
        }

        return $this->_entriesModel->getStatus($getStatus);
    }

    /**
     * Add a new language variable
     *
     * @return void
     */
    public function addVariableAction()
    {
        $error  = array();
        $newVar = '';
        if (!$this->_userModel->hasRight('addVar')) {
            $error = array('You are not allowed to add a new language variable!');
        }
        // check if user filtered by file template on index page and then clicked "add key"
        $fileTemplateFilter = $this->_request->getParam('fileTemplateFilter', false);
        if ($fileTemplateFilter !== false && $fileTemplateFilter > 0) {
            $fileTemplate = $fileTemplateFilter;
        } else {
            // retrieve from formerly added key or fall back to session
            $fileTemplate = $this->_request->getParam(
                'fileTemplate',
                $this->_dynamicConfig->getParam('entries.addVar.fileTemplate')
            );
        }
        $this->_dynamicConfig->setParam('entries.addVar.fileTemplate', $fileTemplate);

        if (empty($error) && $this->_request->isPost() && $this->_request->getParam('var') !== null) {
            if ($this->_request->getParam('cancel') != null) {
                $this->_myForward('index');

                return;
            }
            $newVar = trim($this->_request->getParam('var'));
            $this->_dynamicConfig->setParam('entries.fileTemplate', $fileTemplate);

            if (!$this->_entriesModel->validateLanguageKey($newVar, $fileTemplate)) {
                $error = array_merge($error, $this->_entriesModel->getValidateMessages());
            }

            if (empty($error)) {
                try {
                    $this->_entriesModel->saveNewKey($newVar, $fileTemplate);
                    $entry        = $this->_entriesModel->getEntryByKey($newVar, $fileTemplate);
                    $historyModel = new Application_Model_History();
                    $historyModel->logNewVarCreated($entry['id']);
                    $this->view->entry = $entry;
                    $this->_request->setParam('id', $entry['id']);
                    $this->_myForward('edit');

                    return;
                } catch (Exception $e) {
                    $error = array('Technical error: Couldn\'t create new language variable.<br />' . $e->getMessage());
                }
            }
        }
        if (!empty($error)) {
            $this->view->error = implode('<br />', $error);
        }
        $this->view->newVar               = $newVar;
        $fileTemplatesModel               = new Application_Model_FileTemplates();
        $this->view->fileTemplates        = $fileTemplatesModel->getFileTemplates('name');
        $this->view->fileTemplateSelected = $this->_dynamicConfig->getParam('entries.addVar.fileTemplate', 0);
    }

    /**
     * Find next untranslated key
     *
     * @return void
     */
    public function getNextUntranslatedKeyAction()
    {
        $languageId  = (int)$this->_request->getParam('languageId');
        $skippedKeys = $this->_getLanguageKeyOffset($languageId);
        $skippedKeys[$languageId]++;
        $id = $this->_findNextUntranslated($languageId, $skippedKeys[$languageId]);
        if ($id === null) {
            // nothing found - decrease offset and fetch entry we came from
            $skippedKeys[$languageId]--;
            $id = $this->_request->getParam('entryId');
        }
        $this->_setKeyOffsetAndForwardToEditAction($id, $skippedKeys);
    }

    /**
     * Find previous or first untranslated key
     *
     * @return void
     */
    public function getPreviousUntranslatedKeyAction()
    {
        $languageId  = (int)$this->_request->getParam('languageId');
        $skippedKeys = $this->_getLanguageKeyOffset($languageId);
        $skippedKeys[$languageId]--;

        $id = $this->_findNextUntranslated($languageId, $skippedKeys[$languageId]);
        if ($id === null) {
            // nothing found - reset offset and fetch entry we came from
            $skippedKeys[$languageId] = 0;
            $id                       = $this->_request->getParam('entryId');
        }
        if ($skippedKeys[$languageId] < 0) {
            $skippedKeys[$languageId] = 0;
        }
        $this->_setKeyOffsetAndForwardToEditAction($id, $skippedKeys);
    }

    /**
     * Handle delete action
     *
     * @return void
     */
    public function deleteAction()
    {
        // check, that the user really has delete rights
        if ($this->_userModel->hasRight('addVar')) {
            $id                     = $this->_request->getParam('id');
            $entry                  = $this->_entriesModel->getKeyById($id);
            $res                    = $this->_entriesModel->deleteEntryByKeyId($id);
            $this->view->keyDeleted = $res;
            if ($res) {
                $historyModel = new Application_Model_History();
                $historyModel->logVarDeleted($entry['key']);
            }
        }
        $this->_myForward('index');
    }

    /**
     * Init form params.
     * Makes sure that form params are defined and saved to dynamic Config.
     *
     * @return void
     */
    private function _initParams()
    {
        // 'name' => array(numeric true|false, default-value)
        $params = array(
            'filterValues'       => array(false, ''),
            'filterKeys'         => array(false, ''),
            'offset'             => array(true, 0),
            'recordsPerPage'     => array(true, 10),
            'getUntranslated'    => array(true, 0),
            'fileTemplateFilter' => array(false, '')
        );
        foreach ($params as $name => $values) {
            list($mode, $default) = $values;
            $this->_mergeParam($name, $mode, $default);
        }
    }

    /**
     * Get value from POST/GET then fallback to dynamicConfig then fallback to user setting.
     *
     * @param string $name    Name of parameter to get
     * @param bool   $numeric True if value needs to be an integer
     * @param string $default The default value if setting can't be get
     *
     * @return int|mixed
     */
    private function _mergeParam($name, $numeric = false, $default = '')
    {
        $value = $this->_request->getParam($name, null);
        if ($value === null) {
            $value = $this->_dynamicConfig->getParam('entries.' . $name, null);
            if ($value === null) {
                $value = $this->_userModel->loadSetting($name, $default);
            }
        };

        if ($numeric !== false) {
            $value = (int)$value;
        }
        // save to session
        $this->_dynamicConfig->setParam('entries.' . $name, $value);
    }

    /**
     * Assign params to view (formerly taken from post or session)
     *
     * @return void
     */
    private function _assignVars()
    {
        $this->view->filterValues    = $this->_dynamicConfig->getParam('entries.filterValues');
        $this->view->filterKeys      = $this->_dynamicConfig->getParam('entries.filterKeys');
        $this->view->offset          = $this->_dynamicConfig->getParam('entries.offset');
        $this->view->recordsPerPage  = $this->_dynamicConfig->getParam('entries.recordsPerPage');
        $this->view->getUntranslated = $this->_dynamicConfig->getParam('entries.getUntranslated');
    }

    /**
     * Save and log changes
     *
     * @param bool $ignoreSmallChange If set to false other languages are not flagged as "please re-check translation"
     *
     * @return bool|string
     */
    private function _saveEntries($ignoreSmallChange = false)
    {
        $params = $this->getRequest()->getParams();

        $nid = (isset($params['nid'])) ? true : false;

        if(isset($params['id'])) {
            $this->_entriesModel->setOnlyCertainShopsFlag($params['id'], $nid);
        }

        $values = array();
        foreach ($params as $name => $val) {
            if (substr($name, 0, 5) == 'edit-') {
                $values[substr($name, 5)] = trim($val);
            }
        }
        $res = true;
        if (isset($params['fileTemplate'])) {
            $res &= $this->_entriesModel->assignFileTemplate($params['id'], $params['fileTemplate']);
        }

        $res &= $this->_entriesModel->saveEntries(
            (int)$params['id'],
            $values,
            $this->_languagesModel->getFallbackLanguageId(), $ignoreSmallChange
        );

        return $res;
    }

    /**
     * Get list of edit languages
     *
     * @return array
     */
    public function getEditLanguages()
    {
        return $this->_userModel->getUserLanguageRights();
    }

    /**
     * Get an entry by it's database id
     *
     * @param int $id Id of entry record
     *
     * @return array
     */
    public function getEntryById($id)
    {
        return $this->_entriesModel->getTranslationsByKeyId($id, $this->_showLanguages);
    }

    /**
     * Set forward flag and forward to action
     *
     * @param string $action Target action
     *
     * @return void
     */
    private function _myForward($action)
    {
        $this->_request->setParam('forwarded', true);
        $this->_forward($action);
    }

    /**
     * Get the skip keys offsets array and apply standard values if not set.
     *
     * @param int|null $languageId Id of language to get the offset for
     *
     * @return array
     */
    private function _getLanguageKeyOffset($languageId = null)
    {
        $skippedKeys = $this->_dynamicConfig->getParam('entries.skippedKeys', array());
        if ($languageId !== null && !isset($skippedKeys[$languageId])) {
            $skippedKeys[$languageId] = 0;
        }

        return $skippedKeys;
    }

    /**
     * Saves the determined entry id and skip key array to session and forwards to the edit action.
     *
     * @param int   $id          Id of entry to edit
     * @param array $skippedKeys Array[langId => offset] holding key offsets per language
     *
     * @return void
     */
    private function _setKeyOffsetAndForwardToEditAction($id, $skippedKeys)
    {
        $this->_dynamicConfig->setParam('entries.skippedKeys', $skippedKeys);
        $this->_request->setParam('id', $id);
        $this->_forward('edit');
    }

    /**
     * Find next untranslated language variable and return its Id.
     *
     * Iterates over all languages the user is allowed to edit.
     *
     * @param int $languageId Language to search in
     * @param int $offset     Number of keys to skip
     *
     * @return int|null
     */
    private function _findNextUntranslated($languageId, $offset = 0)
    {
        $langEnriesModel = new Application_Model_LanguageEntries();

        return $langEnriesModel->getUntranslatedKey($languageId, $offset);
    }

    /**
     * Handle remove-needs-update-flag action
     *
     * @return void
     */
    public function removeNeedsUpdateFlagAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->view->data = null;

        $languageId = (int)$this->_request->getParam('languageId');
        $keyId      = (int)$this->_request->getParam('keyId');

        $languageRights = $this->_userModel->getUserLanguageRights();
        if (!in_array($languageId, $languageRights)) {
            $this->getResponse()->setHttpResponseCode(403);
            $this->view->data = Msd_Language::getInstance()->translate('L_YOU_ARE_NOT_ALLOWED_TO_EDIT_THIS_LANGUAGE');
            $this->render('json');

            return;
        }

        $status = $this->_entriesModel->removeNeedsUpdateFlag($languageId, $keyId);

        if (!$status) {
            $this->getResponse()->setHttpResponseCode(500);
            $this->view->data = Msd_Language::getInstance()->translate('L_ERROR_SAVING_CHANGE');
        }

        $this->render('json');
    }

}
