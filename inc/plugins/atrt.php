<?php
// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Nope.");
}

$plugins->add_hook('forumdisplay_thread','atrt_forumdisplay');
$plugins->add_hook('showthread_start','atrt_showthread');

function atrt_info()
{
	return array(
		"name"			=> "Advanced Thread Rating Tools",
		"description"	=> "Adds additional functionality to thread ratings, including the ability to see who rated threads as well as the ability to delete thread ratings.",
		"website"		=> "https://github.com/PenguinPaul/advanced-thread-rating-tools",
		"author"		=> "Paul H.",
		"authorsite"	=> "http://www.paulhedman.com",
		"version"		=> "1.0",
		"codename"		=> "atrt",
		"compatibility" => "*"
	);
}


function atrt_activate()
{
	global $db;
	
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";

	//get rid of old templates/settings
	atrt_deactivate();
	
	//template
	find_replace_templatesets('forumdisplay_thread_rating',
		'#' . preg_quote('</td>') . '#',
		'{$atrt}</td>'
	);
	
	
	//settings
	$group = array(
		'gid'			=> 'NULL',
		'name'			=> 'atrt',
		'title'			=> 'Advanced Thread Rating Tools Settings',
		'description'	=> '',
		'disporder'		=> "1",
		'isdefault'		=> 'no',
	);

	$db->insert_query('settinggroups', $group);

	$gid = $db->insert_id();
	
	$setting = array(
		'name'			=> 'atrt_groups',
		'title'			=> 'View Groups',
		'description'	=> 'A CSV of groups that can view thread ratings.',
		'optionscode'	=> 'text',
		'value'			=> '2,3,4,6',
		'disporder'		=> 1,
		'gid'			=> intval($gid),
	);

	$db->insert_query('settings', $setting);
	
	$setting = array(
		'name'			=> 'atrt_delgroups',
		'title'			=> 'Delete Groups',
		'description'	=> 'A CSV of groups that can delete their own ratings (They must also be in the above group as well.  Moderators can delete all.)',
		'optionscode'	=> 'text',
		'value'			=> '2',
		'disporder'		=> 2,
		'gid'			=> intval($gid),
	);

	$db->insert_query('settings', $setting);
	
	$setting = array(
		'name'			=> 'atrt_where',
		'title'			=> 'Links',
		'description'	=> 'Should links to view ratings be on forumdisplay, showthread, or both?  Seperate by commas.  Values should be "forumdisplay", "forumdisplay,showthread", "showthread,forumdisplay", or "showthread" (no quotes)',
		'optionscode'	=> 'text',
		'value'			=> 'forumdisplay,showthread',
		'disporder'		=> 3,
		'gid'			=> intval($gid),
	);
	
	$db->insert_query('settings', $setting);

	
	$setting = array(
		'name'			=> 'atrt_lt',
		'title'			=> 'Forum Display Link',
		'description'	=> 'What you want shown by the ratings to link to the ratings popup.  HTML is allowed. (An image would be &lt;img src="path/to/image.png" /&gt;)',
		'optionscode'	=> 'text',
		'value'			=> '(Who Rated?)',
		'disporder'		=> 4,
		'gid'			=> intval($gid),
	);
	
	$db->insert_query('settings', $setting);
	
	$setting = array(
		'name'			=> 'atrt_st_loc',
		'title'			=> 'Location of showthread link',
		'description'	=> 'Enter "text" if you want the link to the popup on showthread to be the "Thread Rating:" text.  Use "before" or "after" for the value of the setting above to be the link, before/after the "Thread Rating" text,.  Default is after.',
		'optionscode'	=> 'text',
		'value'			=> 'text',
		'disporder'		=> 5,
		'gid'			=> intval($gid),
	);
	
	$db->insert_query('settings', $setting);
	
	rebuild_settings();
}

function atrt_deactivate()
{
	global $db;
	
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";

	//byebye template
	find_replace_templatesets('forumdisplay_thread_rating',
		'#' . preg_quote('{$atrt}') . '#',
		''
	);
	
	$db->query("DELETE FROM ".TABLE_PREFIX."settings WHERE name LIKE 'atrt_%'");
	$db->query("DELETE FROM ".TABLE_PREFIX."settinggroups WHERE name='atrt'");
	rebuild_settings(); 
}

function atrt_forumdisplay()
{
	global $thread,$mybb,$atrt;

	//LOOOOONG if statement... basically, is the forumdisplay ratings link enabled?  If so, can the user view ratings?
	if(in_array($mybb->user['usergroup'],explode(',',$mybb->settings['atrt_groups'])) && in_array('forumdisplay',explode(',',$mybb->settings['atrt_where'])) && $thread['numratings'])
	{
		$atrt = "<span class=\"smalltext\"><a href=\"#\" onclick=\"MyBB.popupWindow('{$mybb->settings['bburl']}/showthread.php?action=whorated&amp;tid={$thread['tid']}', 'atrt', 400, 400);\">".$mybb->settings['atrt_lt']."</a></span>";
	} else {
		//new MyBB coding standard that says to avoid PHP notices even though we supress them
		$atrt = '';
	}	
}

function atrt_showthread()
{
	global $mybb,$db,$theme,$headerinclude,$lang,$thread,$tid;
	
	//are we viewing thread ratings?
	if($mybb->input['action'] == 'whorated')
	{
		//can this user view thread ratings?
		if(!in_array($mybb->user['usergroup'],explode(',',$mybb->settings['atrt_groups'])))
		{
			error_no_permission();
		}
		
		
		//Deleting ratings perhaps?
		
		//This variable states if the user can delete ratings.  We set this to false intially for the same MyBB coding standard mentioned earlier.
		$candelete = false;
		
		if(isset($mybb->input['deleterating']))
		{
			if($mybb->usergroup['canmodcp'] == 1) 
			{
				//this user is a mod, they can delete ratings
				$candelete = true;
			} else {
				//does this rating belong to the current user?
				$myrating = $db->simple_select('threadratings','*',"rid='".intval($mybb->input['deleterating'])."'");
				
				if($db->num_rows($myrating) > 0)
				{
					$candelete = true;
				}
			}
			
			//can the user delete the rating?  has he passed the csrf check?
			if($candelete && verify_post_check($mybb->input['my_post_key'],true))
			{
				//avoid double querying if the user isn't a mod
				if(!isset($myrating))
				{
					$myrating = $db->simple_select('threadratings','*',"rid='".intval($mybb->input['deleterating'])."'");
				}
				
				//get the rating into a nice array
				$rating = $db->fetch_array($myrating);
				
				//update thread ratings in the threads table
				$db->query("UPDATE ".TABLE_PREFIX."threads SET numratings=numratings-1, totalratings=totalratings-{$rating['rating']} WHERE tid='{$rating['tid']}'");
				
				//delete the rating
				$db->delete_query('threadratings',"rid='".intval($mybb->input['deleterating'])."'");
				
				//redirect
				redirect('showthread.php?action=whorated&amp;tid='.$tid, 'Rating deleted.');
			}
		}
		
		//Okay, if the user has gotten this far, they either didn't try to delete anything or wasn't allowed to, so show the ratings :D
		
		//MyBB coding standards
		$ratings = '';

		//get the ratings
		$query = $db->simple_select('threadratings','*',"tid='{$tid}'");
		
		//does this user have the potential to delete their own ratings?
		if(in_array($mybb->user['usergroup'],explode(',',$mybb->settings['atrt_delgroups'])))
		{
			//yes, see if they have any ratings in the thread though
			$urq = $db->simple_select('threadratings','*',"tid='{$tid}' AND uid='{$mybb->user['uid']}'");
			if($db->num_rows($query) != 0)
			{
				$hasrated = true;
			} else {
				$hasrated = false;
			}
		} else {
			$hasrated = false;
		}
		
		$bgcolor = 'trow1';
		
		//get ALL the ratings meme!
		while($rating = $db->fetch_array($query))
		{
			//get the user whose rating this is
			$rater = get_user($rating['uid']);
			
			//format their name
			$rater_name = format_name($rater['username'], $rater['usergroup'], $rater['displaygroup']);
			$profile_link = build_profile_link($rater_name, $rater['uid'], '_blank', 'if(window.opener) { window.opener.location = this.href; return false; }');
			
			//format the time this was rated
			$rating_time = my_date($mybb->settings['dateformat'], $rating['dateline']);
			
			//the rating stars
			$stars  = "<ul class=\"star_rating\" id=\"rating_thread_{$tid}\"><li style=\"width: ". $rating['rating']*20 ."%\" class=\"current_rating\" id=\"current_rating_{$tid}\">1 Votes - 4 Average</li></ul>";
			
			//MyBB coding standards (hereafter referred to as MCS)
			$modoptions = '';
			
			//if the user has a rating they can delete, but it's not this one, then make the time column colspan 2
			if($hasrated && $rating['uid'] != $mybb->user['uid'])
			{
				$nmc = ' colspan="2"';
			} else {
				$nmc = '';
			}
			
			//Another LOOONG if statement. tl;dr: is the user a mod? or can they delete ratings, and this is one of theirs?
			if($mybb->usergroup['canmodcp'] == 1 || (in_array($mybb->user['usergroup'],explode(',',$mybb->settings['atrt_delgroups'])) && $rating['uid'] == $mybb->user['uid']))
			{	
				$modoptions = "<td class=\"{$bgcolor}\"><a href=\"showthread.php?action=whorated&amp;tid={$tid}&amp;deleterating={$rating['rid']}&amp;my_post_key={$mybb->post_code}\">Delete Rating</a></td>";
			}
			
			//rating template
			$ratings .= "<tr>
	<td class=\"{$bgcolor}\">{$profile_link}</td>
	<td class=\"{$bgcolor}\">{$stars}</td>
	<td class=\"{$bgcolor}\"{$nmc}>{$rating_time}</td>
	{$modoptions}</tr>";
		}
		
		//are there any ratings for this thread?
		if($ratings == '')
		{
			//nope.  show an error message
			if(!$thread['numratings'])
			{
				//no ratings
				$ratings = 'This thread has received no ratings.';
			} else {
				//no ratings, but the threads table has ratings... due to improper rating deletion
				$ratings = 'No ratings were found for this thread.  Contact your administrator about rebuilding thread ratings.';
			}
		}
	
	//le template
	$template = "<html>
	<head>
	<title>Thread Ratings for {$thread['subject']}</title>
	{$headerinclude}
	<style type=\"text/css\">
	body {
		text-align: left;
	}
	</style>
	</head>
	<body style=\"margin:0; padding: 4px; top: 0; left: 0;\">
		<table width=\"100%\" cellspacing=\"{$theme['borderwidth']}\" cellpadding=\"{$theme['tablespace']}\" border=\"0\" align=\"center\" class=\"tborder\">
		<tr>
			<td class=\"thead\">
				<div class=\"float_right\" style=\"margin-top: 3px;\"><span class=\"smalltext\"><a href=\"#\" onclick=\"window.close();\">Close</a></span></div>
				<div><strong>Who Rated {$thread['subject']}</strong></div>
			</td>
		</tr>
		<tr>
			<td class=\"trow2\">
				<div style=\"overflow: auto; height: 300px;\">
					<table width=\"100%\" cellspacing=\"{$theme['borderwidth']}\" cellpadding=\"{$theme['tablespace']}\" border=\"0\" align=\"center\" class=\"tborder\" style=\"border: 0;\">
						{$ratings}
					</table>
				</div>
			</td>
		</tr>
		</table>
	</body>
	</html>";
	
	//output this sucker
	output_page($template);
	}

	//woah, that was a long if!  We're now in the showthread if the user ISN'T trying to view ratings.

	//if the user can view ratings and there are ratings, show a link.
	if(in_array($mybb->user['usergroup'],explode(',',$mybb->settings['atrt_groups'])) && in_array('showthread',explode(',',$mybb->settings['atrt_where'])) && $thread['numratings'] > 0)
	{
		if($mybb->settings['atrt_st_loc'] == 'text')
		{
			$lang->thread_rating = "<a href=\"#\" onclick=\"MyBB.popupWindow('{$mybb->settings['bburl']}/showthread.php?action=whorated&amp;tid={$thread['tid']}', 'atrt', 400, 400);\">{$lang->thread_rating}</a>";
		} else if($mybb->settings['atrt_st_loc'] == 'before') {
			$lang->thread_rating = "<a href=\"#\" onclick=\"MyBB.popupWindow('{$mybb->settings['bburl']}/showthread.php?action=whorated&amp;tid={$thread['tid']}', 'atrt', 400, 400);\">{$mybb->settings['atrt_lt']}</a> ".$lang->thread_rating; 
		} else {
			$lang->thread_rating .= "<a href=\"#\" onclick=\"MyBB.popupWindow('{$mybb->settings['bburl']}/showthread.php?action=whorated&amp;tid={$thread['tid']}', 'atrt', 400, 400);\">{$mybb->settings['atrt_lt']}</a>"; 
		}	
	}
}

?>
