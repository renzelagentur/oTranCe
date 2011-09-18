<?php
/**
 * This file is part of oTranCe released under the GNU GPL 3 license
 * http://www.oTranCe.de
 *
 * @package         oTranCe
 * @subpackage      Importer
 * @version         SVN: $
 * @author          $Author: $
 */

/**
 * Generic semicolon seperated file importer
 *
 * @package         oTranCe
 * @subpackage      Importer
 */
class Module_Import_Ssv extends Module_Import_Csv
{
    /**
     * Key -> Value separator
     * @var string
     */
    protected $_separator = ';';

    /**
     * Get rendered info view
     *
     * @param Zend_View $view View instance
     *
     * @return string
     */
    public function getInfo(Zend_View $view)
    {
        $path = realpath(APPLICATION_PATH . '/../modules/library/Import/views') . DS;
        $view->addScriptPath($path);
        return $view->render('ssv.phtml');
    }
}