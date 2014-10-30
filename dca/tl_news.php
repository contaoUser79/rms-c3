<?php

/**
 * Contao Open Source CMS
 *
 * PHP version 5
 * @copyright  Sven Rhinow Webentwicklung 2014 <http://www.sr-tag.de>
 * @author     Stefan Lindecke  <stefan@ktrion.de>
 * @author     Sven Rhinow <kservice@sr-tag.de>
 * @package    rms for Contao 3 (Release Management System)
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Table tl_news
 */
if($GLOBALS['TL_CONFIG']['rms_active'])
{
	$this->loadLanguageFile('tl_default');	
		
    $GLOBALS['TL_DCA']['tl_news']['config']['onload_callback'][] = array('tl_news_rms','addRmsFields');
    $GLOBALS['TL_DCA']['tl_news']['list']['operations']['toggle']['button_callback'] = array('tl_news_rms','toggleIcon');

    /**
    * add operation show Preview
    */
    $GLOBALS['TL_DCA']['tl_news']['list']['operations']['showPreview'] = array
	(
		'label'               => &$GLOBALS['TL_LANG']['tl_calendar_events']['show_preview'],
		'href'                => 'key=showPreview',
		'class'               => 'browser_preview',
		'icon'                => 'page.gif',
		'attributes'          => 'target="_blank"',
		'button_callback' => array('tl_news_rms','checkPreviewIcon')
	);
}

/**
* Fields
*/
$GLOBALS['TL_DCA']['tl_news']['fields']['ptable']['ignoreDiff'] = true;

$GLOBALS['TL_DCA']['tl_news']['fields']['rms_first_save'] = array
(
	'sql'					  => "char(1) NOT NULL default ''",
	'ignoreDiff'			=> true,
);

$GLOBALS['TL_DCA']['tl_news']['fields']['rms_new_edit'] = array
(
	'sql'					  => "char(1) NOT NULL default ''"
);

$GLOBALS['TL_DCA']['tl_news']['fields']['rms_ref_table'] = array
(
	'sql'					  => "char(55) NOT NULL default ''",
	'ignoreDiff'			=> true,
);

$GLOBALS['TL_DCA']['tl_news']['fields']['rms_notice'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['MSC']['rms_notice'],
	'exclude'                 => true,
	'search'                  => true,
	'inputType'               => 'textarea',
	'eval'                    => array('mandatory'=>false, 'rte'=>FALSE),
	'sql'					  => "longtext NULL"
);

$GLOBALS['TL_DCA']['tl_news']['fields']['rms_release_info'] = array
(
	'label'                   => &$GLOBALS['TL_LANG']['MSC']['rms_release_info'],
	'exclude'                 => true,
	'inputType'               => 'checkbox',
	'sql'					  => "char(1) NOT NULL default ''",
	'ignoreDiff'			=> true,
	'save_callback' => array
	(
		array('SvenRhinow\rms\rmsHelper', 'sendEmailInfo')
	)
);

/**
 * Class tl_news_rms
 *
 * Provide miscellaneous methods that are used by the data configuration array.
 * @copyright  Sven Rhinow
 * @author     sr-tag Sven Rhinow Webentwicklung <https://www.sr-tag.de>
 * @package    Controller
 */
class tl_news_rms extends \Backend
{

    /**
     * Import the back end user object
     */
    public function __construct()
    {
		parent::__construct();
		$this->import('BackendUser', 'User');
		$this->import('Database');
    }

    /**
     * Return the "toggle send-button"
     * @param array
     * @param string
     * @param string
     * @param string
     * @param string
     * @param string
     * @return string
     */
    public function toggleIcon($row, $href, $label, $title, $icon, $attributes)
    {

		//test rms
		$rmsObj = $this->Database->prepare('SELECT * FROM `tl_rms` WHERE `ref_table`=? AND `ref_id`=?')
					 ->execute('tl_news',$row['id']);

		if($rmsObj->numRows > 0)
		{
			return '';
		}
		else
		{
			if (strlen($this->Input->get('tid')))
			{
				$this->toggleVisibility($this->Input->get('tid'), ($this->Input->get('state') == 1));
				$this->redirect($this->getReferer());
			}

			// Check permissions AFTER checking the tid, so hacking attempts are logged
			if (!$this->User->isAdmin && !$this->User->hasAccess('tl_news::published', 'alexf'))
			{
				return '';
			}

			$href .= '&amp;tid='.$row['id'].'&amp;state='.($row['published'] ? '' : 1);

			if (!$row['published'])
			{
				$icon = 'invisible.gif';
			}

			return '<a href="'.$this->addToUrl($href).'" title="'.specialchars($title).'"'.$attributes.'>'.$this->generateImage($icon, $label).'</a> ';
		}
   }

    /**
     * Disable/enable a user group
     * @param integer
     * @param boolean
     */
    public function toggleVisibility($intId, $blnVisible)
    {
	    // Check permissions to edit
	    $this->Input->setGet('id', $intId);
	    $this->Input->setGet('act', 'toggle');

	    // Check permissions to publish
	    if (!$this->User->isAdmin && !$this->User->hasAccess('tl_news::published', 'alexf'))
	    {
		    $this->log('Not enough permissions to publish/unpublish news item ID "'.$intId.'"', 'tl_news toggleVisibility', TL_ERROR);
		    $this->redirect('contao/main.php?act=error');
	    }

	    $this->createInitialVersion('tl_news', $intId);

	    // Trigger the save_callback
	    if (is_array($GLOBALS['TL_DCA']['tl_news']['fields']['published']['save_callback']))
	    {
		    foreach ($GLOBALS['TL_DCA']['tl_news']['fields']['published']['save_callback'] as $callback)
		    {
			    $this->import($callback[0]);
			    $blnVisible = $this->$callback[0]->$callback[1]($blnVisible, $this);
		    }
	    }

	    // Update the database
	    $this->Database->prepare("UPDATE tl_news SET tstamp=". time() .", published='" . ($blnVisible ? 1 : '') . "' WHERE id=?")
				       ->execute($intId);

	    $this->createNewVersion('tl_news', $intId);

	    // Update the RSS feed (for some reason it does not work without sleep(1))
	    sleep(1);
	    $this->import('News');
	    $this->News->generateFeed(CURRENT_ID);
    }

    /**
     * Return the "toggle preview-button"
     * @param array
     * @param string
     * @param string
     * @param string
     * @param string
     * @param string
     * @return string
     */
    public function checkPreviewIcon($row, $href, $label, $title, $icon, $attributes)
    {
        $this->import('Database');
        $this->import('SvenRhinow\rms\rmsHelper','rmsHelper');
        $previewLink = $this->rmsHelper->getPreviewLink($row['id'],\Input::get('table'));

        //test rms
        $rmsObj = $this->Database->prepare('SELECT * FROM `tl_rms` WHERE `ref_table`=? AND `ref_id`=?')
				 ->execute('tl_news',$row['id']);

        if($rmsObj->numRows > 0) return '<a href="'.$previewLink.'" title="'.specialchars($title).'"'.$attributes.'>'.$this->generateImage($icon, $label).'</a> ';
        else return '';

    }

	/**
	* add RMS-Fields in menny content-elements (DCA)
	* @var object
	*/
	public function addRmsFields(\DataContainer $dc)
	{
	    $strTable = $this->Input->get("table");

	    //defined blacklist palettes
		$rm_palettes_blacklist = array('__selector__');

	    //add Field in meny content-elements
		foreach($GLOBALS['TL_DCA'][$strTable]['palettes'] as $name => $field)
        {
			if(in_array($name,$rm_palettes_blacklist)) continue;

			$GLOBALS['TL_DCA'][$strTable]['palettes'][$name] .=  ';{rms_legend:hide},rms_notice,rms_release_info';
        }

	}

	/**
	* custom modify the rms-Preview
	* used from rmsHelper->modifyForPreview() -> is a parseTemplate->HOOK
	* @param object
	* @param array
	* @return object
	*/
	public function modifyForPreview($templObj, $newArr)
	{
		global $objPage;

		$origObj = clone $templObj;

	    if(is_array($newArr) && count($newArr) > 0)
	    {
	 		foreach($newArr as $k => $v)
	 		{			    
			    $templObj->$k = $v;
	 		}

			$templObj->newsHeadline = $templObj->headline;
			$templObj->linkHeadline = str_replace($origObj->headline,$templObj->headline, $templObj->linkHeadline);
			$templObj->date = \Date::parse($objPage->datimFormat, $templObj->time);
			
			//author
			$objAuthor = $this->Database->prepare('SELECT * FROM `tl_user` WHERE `id`=?')->limit(1)->execute($templObj->author);
			if($objAuthor->numRows > 0) $templObj->author = $GLOBALS['TL_LANG']['MSC']['by'] . ' ' . $objAuthor->name;
	    }
	    return $templObj;
	}

}
