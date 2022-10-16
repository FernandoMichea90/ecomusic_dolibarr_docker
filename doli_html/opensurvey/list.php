<?php
/* Copyright (C) 2013-2017 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2014      Marcos García       <marcosgdf@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/opensurvey/list.php
 *	\ingroup    opensurvey
 *	\brief      Page to list surveys
 */

require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/files.lib.php";
require_once DOL_DOCUMENT_ROOT."/opensurvey/class/opensurveysondage.class.php";

// Load translation files required by the page
$langs->load("opensurvey");

$action     = GETPOST('action', 'aZ09') ?GETPOST('action', 'aZ09') : 'view'; // The action 'add', 'create', 'edit', 'update', 'view', ...
$massaction = GETPOST('massaction', 'alpha'); // The bulk action (combo box choice into lists)
$show_files = GETPOST('show_files', 'int'); // Show files area generated by bulk actions ?
$confirm    = GETPOST('confirm', 'alpha'); // Result of a confirmation
$cancel     = GETPOST('cancel', 'alpha'); // We click on a Cancel button
$toselect   = GETPOST('toselect', 'array'); // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ?GETPOST('contextpage', 'aZ') : 'opensurveylist'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha'); // Go back to a dedicated page
$optioncss  = GETPOST('optioncss', 'aZ'); // Option for the css output (always '' except when 'print')

$id = GETPOST('id', 'alpha');
$search_ref = GETPOST('search_ref', 'alpha');
$search_title = GETPOST('search_title', 'alpha');
$search_status = GETPOST('search_status', 'alpha');

// Load variable for pagination
$limit = GETPOST('limit', 'int') ?GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha') || (empty($toselect) && $massaction === '0')) {
	$page = 0;
}     // If $page is not defined, or '' or -1 or if we click on clear filters or if we select empty mass action
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Initialize technical objects
$object = new Opensurveysondage($db);
$opensurvey_static = new Opensurveysondage($db);

$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->opensurvey->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array('surveylist')); // Note that conf->hooks_modules contains array
// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);
$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Default sort order (if not yet defined by previous GETPOST)
if (!$sortfield) {
	$sortfield = "p.date_fin";
}
if (!$sortorder) {
	$sortorder = "DESC";
}

// Security check
if (!$user->rights->opensurvey->read) {
	accessforbidden();
}

// Definition of fields for list
$arrayfields = array();
foreach ($arrayfields as $key => $val) {
	// If $val['visible']==0, then we never show the field
	if (!empty($val['visible'])) {
		$arrayfields['t.'.$key] = array('label'=>$val['label'], 'checked'=>(($val['visible'] < 0) ? 0 : 1), 'enabled'=>$val['enabled'], 'position'=>$val['position']);
	}
}
// Extra fields
if (is_array($extrafields->attributes[$object->table_element]['label']) && count($extrafields->attributes[$object->table_element]['label']) > 0) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		if (!empty($extrafields->attributes[$object->table_element]['list'][$key])) {
			$arrayfields["ef.".$key] = array(
				'label'=>$extrafields->attributes[$object->table_element]['label'][$key],
				'checked'=>(($extrafields->attributes[$object->table_element]['list'][$key] < 0) ? 0 : 1),
				'position'=>$extrafields->attributes[$object->table_element]['pos'][$key],
				'enabled'=>(abs($extrafields->attributes[$object->table_element]['list'][$key]) != 3 && $extrafields->attributes[$object->table_element]['perms'][$key])
			);
		}
	}
}
$object->fields = dol_sort_array($object->fields, 'position');
$arrayfields = dol_sort_array($arrayfields, 'position');

$permissiontoread = $user->rights->opensurvey->read;
$permissiontoadd = $user->rights->opensurvey->write;
// permission delete doesn't exists
$permissiontodelete = $user->rights->opensurvey->write;


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list'; $massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		$search_status = '';
		$search_title = '';
		$search_ref = '';
		$toselect = '';
		$search_array_options = array();
	}
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
		|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
		$massaction = ''; // Protection to avoid mass action if we force a new search during a mass action confirmation
	}

	// Mass actions
	$objectclass = 'Opensurveysondage';
	$objectlabel = 'Opensurveysondage';
	$uploaddir = $conf->opensurvey->dir_output;
	include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';
}


/*
 * View
 */

$form = new Form($db);

$now = dol_now();

//$help_url="EN:Module_MyObject|FR:Module_MyObject_FR|ES:Módulo_MyObject";
$help_url = '';
$title = $langs->trans('OpenSurveyArea');


$sql = "SELECT p.id_sondage as rowid, p.fk_user_creat, p.format, p.date_fin, p.status, p.titre as title, p.nom_admin, p.tms,";
$sql .= " u.login, u.firstname, u.lastname";
$sql .= " FROM ".MAIN_DB_PREFIX."opensurvey_sondage as p";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = p.fk_user_creat";
$sql .= " WHERE p.entity IN (".getEntity('survey').")";
if ($search_status != '-1' && $search_status != '') {
	$sql .= natural_search("p.status", $search_status, 2);
}
if ($search_expired == 'expired') {
	$sql .= " AND p.date_fin < '".$db->idate($now)."'";
}
if ($search_expired == 'opened') {
	$sql .= " AND p.date_fin >= '".$db->idate($now)."'";
}
if ($search_ref) {
	$sql .= natural_search("p.id_sondage", $search_ref);
}
if ($search_title) {
	$sql .= natural_search("p.titre", $search_title);
}
// Add where from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';
// Add where from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$nbtotalofrecords = '';
if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
	$resql = $db->query($sql);
	$nbtotalofrecords = $db->num_rows($resql);
	if (($page * $limit) > $nbtotalofrecords) {	// if total of record found is smaller than page * limit, goto and load page 0
		$page = 0;
		$offset = 0;
	}
}
// if total of record found is smaller than limit, no need to do paging and to restart another select with limits set.
if (is_numeric($nbtotalofrecords) && $limit > $nbtotalofrecords) {
	$num = $nbtotalofrecords;
} else {
	$sql .= $db->plimit($limit + 1, $offset);

	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		exit;
	}

	$num = $db->num_rows($resql);
}

// Direct jump if only one record found
if ($num == 1 && !empty($conf->global->MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE) && $search_all) {
	$obj = $db->fetch_object($resql);
	$id = $obj->rowid;
	header("Location: ".dol_buildpath('/opensurvey/card.php', 1).'?id='.$id);
	exit;
}


// Output page
// --------------------------------------------------------------------

llxHeader('', $title, $help_url);

$arrayofselected = is_array($toselect) ? $toselect : array();

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.urlencode($limit);
}
$fieldtosortuser = empty($conf->global->MAIN_FIRSTNAME_NAME_POSITION) ? 'firstname' : 'lastname';

if ($optioncss != '') {
	$param .= '&optioncss='.urlencode($optioncss);
}
// Add $param from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_param.tpl.php';

// List of mass actions available
$arrayofmassactions = array(
	//'presend'=>img_picto('', 'email', 'class="pictofixedwidth"').$langs->trans("SendByMail"),
	//'builddoc'=>img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans("PDFMerge"),
);
if ($permissiontodelete) {
	$arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete");
}
if (GETPOST('nomassaction', 'int') || in_array($massaction, array('presend', 'predelete'))) {
	$arrayofmassactions = array();
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);


// List of surveys into database

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

$newcardbutton = dolGetButtonTitle($langs->trans('NewSurvey'), '', 'fa fa-plus-circle', DOL_URL_ROOT.'/opensurvey/wizard/index.php', '', $user->rights->opensurvey->write);

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'poll', 0, $newcardbutton, '', $limit, 0, 0, 1);

// Add code for pre mass action (confirmation or email presend form)
$topicmail = "SendOpenSurveyRef";
$modelmail = "opensurvey";
$objecttmp = new Opensurveysondage($db);
$trackid = 'surv'.$object->id;
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

if ($sall) {
	foreach ($fieldstosearchall as $key => $val) {
		$fieldstosearchall[$key] = $langs->trans($val);
	}
	print '<div class="divsearchfieldfilter">'.$langs->trans("FilterOnInto", $sall).join(', ', $fieldstosearchall).'</div>';
}

$moreforfilter = '';
/*$moreforfilter.='<div class="divsearchfield">';
$moreforfilter.= $langs->trans('MyFilter') . ': <input type="text" name="search_myfield" value="'.dol_escape_htmltag($search_myfield).'">';
$moreforfilter.= '</div>';*/

$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object); // Note that $action and $object may have been modified by hook
if (empty($reshook)) {
	$moreforfilter .= $hookmanager->resPrint;
} else {
	$moreforfilter = $hookmanager->resPrint;
}

if (!empty($moreforfilter)) {
	print '<div class="liste_titre liste_titre_bydiv centpercent">';
	print $moreforfilter;
	print '</div>';
}

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
//$selectedfields=$form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage);	// This also change content of $arrayfields
$selectedfields = '';
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

print '<div class="div-table-responsive">';
print '<table class="tagtable liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

// Fields title search
// --------------------------------------------------------------------
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="maxwidth100onsmartphone" name="search_title" value="'.dol_escape_htmltag($search_title).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
$arraystatus = array('-1'=>'&nbsp;', '0'=>$langs->trans("Draft"), '1'=>$langs->trans("Opened"), '2'=>$langs->trans("Closed"));
print '<td class="liste_titre" align="center">'.$form->selectarray('search_status', $arraystatus, $search_status).'</td>';
// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_input.tpl.php';

// Fields from hook
$parameters = array('arrayfields'=>$arrayfields);
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
// Action column
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";


// Fields title label
// --------------------------------------------------------------------
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "p.id_sondage", $param, "", "", $sortfield, $sortorder);
print_liste_field_titre("Title", $_SERVER["PHP_SELF"], "p.titre", $param, "", "", $sortfield, $sortorder);
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "p.format", $param, "", "", $sortfield, $sortorder);
print_liste_field_titre("Author", $_SERVER["PHP_SELF"], "u.".$fieldtosortuser, $param, "", "", $sortfield, $sortorder);
print_liste_field_titre("NbOfVoters", $_SERVER["PHP_SELF"], "", $param, "", 'align="right"', $sortfield, $sortorder);
print_liste_field_titre("ExpireDate", $_SERVER["PHP_SELF"], "p.date_fin", $param, "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre("DateLastModification", $_SERVER["PHP_SELF"], "p.tms", $param, "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "p.status", $param, "", 'align="center"', $sortfield, $sortorder);
// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';
// Hook fields
$parameters = array('arrayfields'=>$arrayfields, 'param'=>$param, 'sortfield'=>$sortfield, 'sortorder'=>$sortorder);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
// Action column
print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', 'align="center"', $sortfield, $sortorder, 'maxwidthsearch ')."\n";
print '</tr>'."\n";



// Loop on record
// --------------------------------------------------------------------
$i = 0;
$totalarray = array();
$totalarray['nbfield'] = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break; // Should not happen
	}

	$sql2 = 'select COUNT(*) as nb from '.MAIN_DB_PREFIX."opensurvey_user_studs where id_sondage='".$db->escape($obj->rowid)."'";
	$resql2 = $db->query($sql2);
	if ($resql2) {
		$obj2 = $db->fetch_object($resql2);
		$nbuser = $obj2->nb;
	} else {
		dol_print_error($db);
	}

	$opensurvey_static->id = $obj->rowid;
	$opensurvey_static->ref = $obj->rowid;
	$opensurvey_static->title = $obj->title;
	$opensurvey_static->status = $obj->status;
	$opensurvey_static->date_fin = $db->jdate($obj->date_fin);

	// Show here line of result
	print '<tr class="oddeven">';

	// Ref
	print '<td>';
	print $opensurvey_static->getNomUrl(1);
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Title
	print '<td>'.dol_htmlentities($obj->title).'</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Type
	print '<td>';
	$type = ($obj->format == 'A') ? 'classic' : 'date';
	print img_picto('', dol_buildpath('/opensurvey/img/'.($type == 'classic' ? 'chart-32.png' : 'calendar-32.png'), 1), 'width="16"', 1);
	print ' '.$langs->trans($type == 'classic' ? "TypeClassic" : "TypeDate");
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	print '<td>';
	// Author
	if ($obj->fk_user_creat) {
		$userstatic = new User($db);
		$userstatic->id = $obj->fk_user_creat;
		$userstatic->firstname = $obj->firstname;
		$userstatic->lastname = $obj->lastname;
		$userstatic->login = $userstatic->getFullName($langs, 0, -1, 48);

		print $userstatic->getLoginUrl(1);
	} else {
		print dol_htmlentities($obj->nom_admin);
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Nb of voters
	print'<td class="right">'.$nbuser.'</td>'."\n";
	if (!$i) {
		$totalarray['nbfield']++;
	}

	print '<td class="center">'.dol_print_date($db->jdate($obj->date_fin), 'day');
	if ($db->jdate($obj->date_fin) < $now && $obj->status == Opensurveysondage::STATUS_VALIDATED) {
		print img_warning($langs->trans("Expired"));
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	print '<td class="center">'.dol_print_date($db->jdate($obj->tms), 'dayhour');
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	print '<td class="center">'.$opensurvey_static->getLibStatut(5).'</td>'."\n";
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Extra fields
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_print_fields.tpl.php';
	// Fields from hook
	$parameters = array('arrayfields'=>$arrayfields, 'obj'=>$obj, 'i'=>$i, 'totalarray'=>&$totalarray);
	$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	// Action column
	print '<td class="nowrap center">';
	if ($massactionbutton || $massaction) {   // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
		$selected = 0;
		if (in_array($obj->rowid, $arrayofselected)) {
			$selected = 1;
		}
		print '<input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"'.($selected ? ' checked="checked"' : '').'>';
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	print '</tr>'."\n";
	$i++;
}

// Show total line
include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';


// If no record found
if ($num == 0) {
	$colspan = 8;
	foreach ($arrayfields as $key => $val) {
		if (!empty($val['checked'])) {
			$colspan++;
		}
	}
	print '<tr><td colspan="'.$colspan.'" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
}


$db->free($resql);

$parameters = array('arrayfields'=>$arrayfields, 'sql'=>$sql);
$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";

if (in_array('builddoc', $arrayofmassactions) && ($nbtotalofrecords === '' || $nbtotalofrecords)) {
	$hidegeneratedfilelistifempty = 1;
	if ($massaction == 'builddoc' || $action == 'remove_file' || $show_files) {
		$hidegeneratedfilelistifempty = 0;
	}

	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
	$formfile = new FormFile($db);

	// Show list of available documents
	$urlsource = $_SERVER['PHP_SELF'].'?sortfield='.$sortfield.'&sortorder='.$sortorder;
	$urlsource .= str_replace('&amp;', '&', $param);

	$filedir = $diroutputmassaction;
	$genallowed = $permissiontoread;
	$delallowed = $permissiontoadd;

	print $formfile->showdocuments('massfilesarea_mymodule', '', $filedir, $urlsource, 0, $delallowed, '', 1, 1, 0, 48, 1, $param, $title, '', '', '', null, $hidegeneratedfilelistifempty);
}

// End of page
llxFooter();
$db->close();
