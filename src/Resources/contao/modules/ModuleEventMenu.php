<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @package   Contao
 * @author    Kester Mielke
 * @license   LGPL
 * @copyright Kester Mielke 2010-2013
 */

namespace Kmielke\CalendarExtendedBundle;

use Contao\BackendTemplate;
use Contao\StringUtil;
use Contao\System;

/**
 * Class ModuleEventMenuExt
 *
 * @copyright  Kester Mielke 2010-2013
 * @author     Kester Mielke
 * @package    Devtools
 */
class ModuleEventMenu extends ModuleCalendar
{

    /**
     * Display a wildcard in the back end
     *
     * @return string
     */
    public function generate()
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
        {
            $objTemplate = new BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### ' . $GLOBALS['TL_LANG']['FMD']['eventmenu'][0] . ' ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = StringUtil::specialcharsUrl(System::getContainer()->get('router')->generate('contao_backend', array('do'=>'themes', 'table'=>'tl_module', 'act'=>'edit', 'id'=>$this->id)));

            return $objTemplate->parse();
        }

        if ($this->cal_format == 'cal_day')
        {
            $this->strTemplate = 'mod_calendar';
            $this->cal_ctemplate = 'cal_mini';
        }

        return parent::generate();
    }


    /**
     * Generate the module
     */
    protected function compile()
    {
        switch ($this->cal_format) {
            case 'cal_year':
                $this->compileYearlyMenu();
                break;

            default:
            case 'cal_month':
                $this->compileMonthlyMenu();
                break;

            case 'cal_day':
                $this->cal_ctemplate = 'cal_mini';
                parent::compile();
                break;
        }
    }


    /**
     * Generate the yearly menu
     */
    protected function compileYearlyMenu()
    {
        $arrData = array();

        if ($this->customTpl) {
            $strTemplate = $this->customTpl;
        } else {
            $strTemplate = 'mod_eventmenu';
        }

        /** @var \Contao\FrontendTemplate|object $objTemplate */
        $objTemplate = new \Contao\FrontendTemplate($strTemplate);

        $this->Template = $objTemplate;
        $arrAllEvents = $this->getAllEventsExt($this->cal_calendar, 0, 2145913200, array($this->cal_holiday));

        foreach ($arrAllEvents as $intDay => $arrDay) {
            foreach ($arrDay as $arrEvents) {
                $arrData[substr($intDay, 0, 4)] += count($arrEvents);
            }
        }

        // Sort data
        ($this->cal_order == 'ascending') ? ksort($arrData) : krsort($arrData);

        $arrItems = array();
        $count = 0;
        $limit = count($arrData);

        // Prepare navigation
        foreach ($arrData as $intYear => $intCount) {
            $intDate = $intYear;
            $quantity = sprintf((($intCount < 2) ? $GLOBALS['TL_LANG']['MSC']['entry'] : $GLOBALS['TL_LANG']['MSC']['entries']), $intCount);

            $arrItems[$intYear]['date'] = $intDate;
            $arrItems[$intYear]['link'] = $intYear;
            $arrItems[$intYear]['href'] = $this->strLink . '?year=' . $intDate;
            $arrItems[$intYear]['title'] = StringUtil::specialchars($intYear . ' (' . $quantity . ')');
            $arrItems[$intYear]['class'] = trim(((++$count == 1) ? 'first ' : '') . (($count == $limit) ? 'last' : ''));
            $arrItems[$intYear]['isActive'] = (\Contao\Input::get('year') == $intDate);
            $arrItems[$intYear]['quantity'] = $quantity;
        }

        $this->Template->items = $arrItems;
        $this->Template->showQuantity = $this->cal_showQuantity;
        $this->Template->yearly = true;
    }


    /**
     * Generate the monthly menu
     */
    protected function compileMonthlyMenu()
    {
        $arrData = array();

        /** @var \Contao\FrontendTemplate|object $objTemplate */
        $objTemplate = new \Contao\FrontendTemplate('mod_eventmenu');

        $this->Template = $objTemplate;
        $arrAllEvents = $this->getAllEventsExt($this->cal_calendar, 0, 2145913200, array($this->cal_holiday));

        foreach ($arrAllEvents as $intDay => $arrDay) {
            foreach ($arrDay as $arrEvents) {
                $arrData[substr($intDay, 0, 4)][substr($intDay, 4, 2)] += count($arrEvents);
            }
        }

        // Sort data
        foreach (array_keys($arrData) as $key) {
            ($this->cal_order == 'ascending') ? ksort($arrData[$key]) : krsort($arrData[$key]);
        }

        ($this->cal_order == 'ascending') ? ksort($arrData) : krsort($arrData);

        $arrItems = array();

        // Prepare the navigation
        foreach ($arrData as $intYear => $arrMonth) {
            $count = 0;
            $limit = count($arrMonth);

            foreach ($arrMonth as $intMonth => $intCount) {
                $intDate = $intYear . $intMonth;
                $intMonth = (intval($intMonth) - 1);

                $quantity = sprintf((($intCount < 2) ? $GLOBALS['TL_LANG']['MSC']['entry'] : $GLOBALS['TL_LANG']['MSC']['entries']), $intCount);

                $arrItems[$intYear][$intMonth]['date'] = $intDate;
                $arrItems[$intYear][$intMonth]['link'] = $GLOBALS['TL_LANG']['MONTHS'][$intMonth] . ' ' . $intYear;
                $arrItems[$intYear][$intMonth]['href'] = $this->strLink . '?month=' . $intDate;
                $arrItems[$intYear][$intMonth]['title'] = StringUtil::specialchars($GLOBALS['TL_LANG']['MONTHS'][$intMonth] . ' ' . $intYear . ' (' . $quantity . ')');
                $arrItems[$intYear][$intMonth]['class'] = trim(((++$count == 1) ? 'first ' : '') . (($count == $limit) ? 'last' : ''));
                $arrItems[$intYear][$intMonth]['isActive'] = (\Contao\Input::get('month') == $intDate);
                $arrItems[$intYear][$intMonth]['quantity'] = $quantity;
            }
        }

        $this->Template->items = $arrItems;
        $this->Template->showQuantity = $this->cal_showQuantity;
        $this->Template->url = $this->strLink . '?';
        $this->Template->activeYear = \Contao\Input::get('year');
    }
}
