<?php
/**
* @class wikiController
* @developer NHN (developers@xpressengine.com)
* @brief  wiki controller class
*/
class WikiController extends Wiki
{
	/**
	 * @brief Inserts a wiki document
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return
	 */
	function procWikiInsertDocument() 
	{
		// Create object model of document module
		$oDocumentModel = &getModel('document');
		// Create object controller of document module
		$oDocumentController = &getController('document');
		
		// Check permissions
		if(!$this->grant->write_document) 
		{
			return new Object(-1, 'msg_not_permitted'); 
		}
		
		$entry = Context::get('entry');
		// Get required parameters settings
		$obj = Context::getRequestVars(); 
		$obj->module_srl = $this->module_srl;
		if($this->module_info->use_comment != 'N') 
		{
			$obj->allow_comment = 'Y';
		}
		else
		{
			$obj->allow_comment = 'N';
		}
		
		// Set nick_name
		if(!$obj->nick_name) 
		{
			$logged_info = Context::get('logged_info');
			if($logged_info)
			{
				$obj->nick_name = $logged_info->nick_name;
			}
			else
			{
				$obj->nick_name = 'anonymous';
			}
		}

		// Set member_srl
		if(!$obj->member_srl) 
		{
			$logged_info = Context::get('logged_info');
			if($logged_info)
			{
				$obj->member_srl = $logged_info->member_srl;
			}
			else
			{
				$obj->member_srl = 0;
			}
		}
		
		if($obj->is_notice != 'Y' || !$this->grant->manager) 
		{
			$obj->is_notice = 'N'; 
		}
		
		settype($obj->title, "string");
		if($obj->title == '')
		{
			$obj->title = cut_str(strip_tags($obj->content), 20, '...');
		}
		
		//If you still Untitled
		if($obj->title == '') 
		{
			$obj->title = 'Untitled';
		}
		
		// Check that already exist
		$oDocument = $oDocumentModel->getDocument($obj->document_srl, $this->grant->manager);
		// Get linked docs (by alias)
		// $wiki_text_parser = $this->getWikiTextParser(); 
		// $linked_documents_aliases = $wiki_text_parser->getLinkedDocuments($obj->content);
		
		// Modified if it already exists
		if($oDocument->isExists() && $oDocument->document_srl == $obj->document_srl) 
		{
			// If we have section, update content: retrieve full text and insert new section in it
			$section = Context::get('section');
			// if (isset($section))
			// {
			// 	$full_content = $oDocument->get('content');
			// 	$section_content = $obj->content;

            //     $lang = $this->module_info->markup_type;
            //     // $wt = new WTParser($full_content, $lang);
			// 	// $wt->setText($section_content, (int)$section);
			// 	// $new_content = $wt->getText();

			// 	// $obj->content = $new_content;
			// }

			// $output = $oDocumentController->updateDocument($oDocument, $obj);
			$output = $this->updateDocument($oDocument, $obj);	

			// Have been successfully modified the hierarchy/ alias change
			if($output->toBool()) 
			{
				// Update alias
				$oDocumentController->deleteDocumentAliasByDocument($obj->document_srl); 
				$aliasName = Context::get('alias');
				if(!$aliasName)
				{
					$aliasName = $this->beautifyEntryName($obj->title); 
				}
				$oDocumentController->insertAlias($obj->module_srl, $obj->document_srl, $aliasName);
				
				// Update linked docs
				if(count($linked_documents_aliases) > 0) 
				{
					$oWikiController = &getController('wiki'); 
					$oWikiController->updateLinkedDocuments($obj->document_srl, $linked_documents_aliases, $obj->module_srl);
				}
			}
			$msg_code = 'success_updated';
			// remove document from cache
			$oCacheHandler = CacheHandler::getInstance('object', NULL, TRUE);
			if($oCacheHandler->isSupport()) 
			{
				$object_key = sprintf('%s.%s.php', $obj->document_srl, Context::getLangType()); 
				$cache_key = $oCacheHandler->getGroupKey('wikiContent', $object_key); 
				$oCacheHandler->delete($cache_key);
			}
		} // if this is a new document
		else 
		{
			$output = $oDocumentController->insertDocument($obj); 
			$msg_code = 'success_registed'; 
			$obj->document_srl = $output->get('document_srl');
			
			// Insert Alias
			$aliasName = Context::get('alias');
			if(!$aliasName)
			{
				$aliasName = $this->beautifyEntryName($obj->title); 
			}
			$oDocumentController->insertAlias($obj->module_srl, $obj->document_srl, $aliasName);
			
			// Insert linked docs
			if(count($linked_documents_aliases) > 0) 
			{
				$oWikiController = &getController('wiki'); 
				$oWikiController->insertLinkedDocuments($obj->document_srl, $linked_documents_aliases, $obj->module_srl);
			}

		}
		
		// Stop when an error occurs
		if(!$output->toBool()) 
		{
			return $output; 
		}
		
		$this->recompileTree($this->module_srl);
		
		// Returns the results
		$entry = $oDocumentModel->getAlias($output->get('document_srl'));
		
		// Registration success message
		$this->setMessage($msg_code);
		if($entry) 
		{
			$site_module_info = Context::get('site_module_info'); 
			$url = getNotEncodedSiteUrl($site_module_info->document, '', 'mid', $this->module_info->mid, 'entry', $entry, 'document_srl', '');
		}
		else 
		{
			$url = getNotEncodedSiteUrl($site_module_info->document, '', 'document_srl', $output->get('document_srl'));
		}
		if($section)
		{
			$section_title = Context::get('section_title');
			$url .= '#' . $section_title;
		}
		$this->setRedirectUrl($url);
	}



	/**
	 * Update the document
	 * @param object $source_obj
	 * @param object $obj
	 * @param bool $manual_updated
	 * @return object
	 */
	function updateDocument($source_obj, $obj, $manual_updated = FALSE)
	{
		$logged_info = Context::get('logged_info');

		if(!$manual_updated && !checkCSRF())
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		if(!$source_obj->document_srl || !$obj->document_srl) return new BaseObject(-1,'msg_invalied_request');
		if(!$obj->status && $obj->is_secret == 'Y') $obj->status = 'SECRET';
		if(!$obj->status) $obj->status = 'PUBLIC';

		// Call a trigger (before)
		$output = ModuleHandler::triggerCall('document.updateDocument', 'before', $obj);
		if(!$output->toBool()) return $output;

		// begin transaction
		$oDB = &DB::getInstance();
		$oDB->begin();

		$oModuleModel = getModel('module');
		if(!$obj->module_srl) $obj->module_srl = $source_obj->get('module_srl');
		$module_srl = $obj->module_srl;
		$document_config = $oModuleModel->getModulePartConfig('document', $module_srl);

		if(!$document_config)
		{
			$document_config = new stdClass();
		}

		if(!isset($document_config->use_history)) $document_config->use_history = 'N';
		$bUseHistory = $document_config->use_history == 'Y' || $document_config->use_history == 'Trace';

		if($bUseHistory)
		{
			$args = new stdClass;
			$args->history_srl = getNextSequence();
			$args->document_srl = $obj->document_srl;
			$args->module_srl = $module_srl;
			if($document_config->use_history == 'Y') $args->content = $source_obj->get('content');
			$args->nick_name = $source_obj->get('nick_name');
			$args->member_srl = $source_obj->get('member_srl');
			$args->regdate = $source_obj->get('last_update');
			$args->ipaddress = $source_obj->get('ipaddress');
			$output = executeQuery("document.insertHistory", $args);
		}
		else
		{
			$obj->ipaddress = $_SERVER['REMOTE_ADDR'];
		}

		// List variables
		if($obj->comment_status) $obj->commentStatus = $obj->comment_status;
		if(!$obj->commentStatus) $obj->commentStatus = 'DENY';
		if($obj->commentStatus == 'DENY') $this->_checkCommentStatusForOldVersion($obj);
		if($obj->allow_trackback!='Y') $obj->allow_trackback = 'N';
		if($obj->homepage)
		{
			$obj->homepage = removeHackTag($obj->homepage);
			if(!preg_match('/^[a-z]+:\/\//i',$obj->homepage))
			{
				$obj->homepage = 'http://'.$obj->homepage;
			}
		}
		
		if($obj->notify_message != 'Y') $obj->notify_message = 'N';
		
		// can modify regdate only manager
        $grant = Context::get('grant');
		if(!$grant->manager)
		{
			unset($obj->regdate);
		}
		
		// Serialize the $extra_vars
		if(!is_string($obj->extra_vars)) $obj->extra_vars = serialize($obj->extra_vars);
		// Remove the columns for automatic saving
		unset($obj->_saved_doc_srl);
		unset($obj->_saved_doc_title);
		unset($obj->_saved_doc_content);
		unset($obj->_saved_doc_message);

		$oDocumentModel = getModel('document');
		// Set the category_srl to 0 if the changed category is not exsiting.
		if($source_obj->get('category_srl')!=$obj->category_srl)
		{
			$category_list = $oDocumentModel->getCategoryList($obj->module_srl);
			if(!$category_list[$obj->category_srl]) $obj->category_srl = 0;
		}

		// Change the update order
		$obj->update_order = getNextSequence() * -1;
		// Hash the password if it exists
		if($obj->password)
		{
			$obj->password = getModel('member')->hashPassword($obj->password);
		}

		// If an author is identical to the modifier or history is used, use the logged-in user's information.
		if(Context::get('is_logged') && !$manual_updated)
		{
			if($source_obj->get('member_srl')==$logged_info->member_srl)
			{
				$obj->member_srl = $logged_info->member_srl;
				$obj->user_name = htmlspecialchars_decode($logged_info->user_name);
				$obj->nick_name = htmlspecialchars_decode($logged_info->nick_name);
				$obj->email_address = $logged_info->email_address;
				$obj->homepage = $logged_info->homepage;
			}
		}

		// For the document written by logged-in user however no nick_name exists
		if($source_obj->get('member_srl')&& !$obj->nick_name)
		{
			$obj->member_srl = $source_obj->get('member_srl');
			$obj->user_name = $source_obj->get('user_name');
			$obj->nick_name = $source_obj->get('nick_name');
			$obj->email_address = $source_obj->get('email_address');
			$obj->homepage = $source_obj->get('homepage');
		}
		// If the tile is empty, extract string from the contents.
		$obj->title = htmlspecialchars($obj->title, ENT_COMPAT | ENT_HTML401, 'UTF-8', false);
		settype($obj->title, "string");
		if($obj->title == '') $obj->title = cut_str(strip_tags($obj->content),20,'...');
		// If no tile extracted from the contents, leave it untitled.
		if($obj->title == '') $obj->title = 'Untitled';
		// Remove XE's own tags from the contents.
		$obj->content = preg_replace('!<\!--(Before|After)(Document|Comment)\(([0-9]+),([0-9]+)\)-->!is', '', $obj->content);
		if(Mobile::isFromMobilePhone() && $obj->use_editor != 'Y')
		{
			if($obj->use_html != 'Y')
			{
				$obj->content = htmlspecialchars($obj->content, ENT_COMPAT | ENT_HTML401, 'UTF-8', false);
			}
			$obj->content = nl2br($obj->content);
		}
		// Change not extra vars but language code of the original document if document's lang_code is different from author's setting.
		if($source_obj->get('lang_code') != Context::getLangType())
		{
			// Change not extra vars but language code of the original document if document's lang_code doesn't exist.
			if(!$source_obj->get('lang_code'))
			{
				$lang_code_args->document_srl = $source_obj->get('document_srl');
				$lang_code_args->lang_code = Context::getLangType();
				$output = executeQuery('document.updateDocumentsLangCode', $lang_code_args);
			}
			else
			{
				$extra_content = new stdClass;
				$extra_content->title = $obj->title;
				$extra_content->content = $obj->content;

				$document_args = new stdClass;
				$document_args->document_srl = $source_obj->get('document_srl');
				$document_output = executeQuery('document.getDocument', $document_args);
				$obj->title = $document_output->data->title;
				$obj->content = $document_output->data->content;
			}
		}
		// Remove iframe and script if not a top adminisrator in the session.
		if($logged_info->is_admin != 'Y')
		{
			$obj->content = removeHackTag($obj->content);
		}
		// if temporary document, regdate is now setting
		if($source_obj->get('status') == $this->getConfigStatus('temp')) $obj->regdate = date('YmdHis');

		// Insert data into the DB
		$output = executeQuery('document.updateDocument', $obj);
		if(!$output->toBool())
		{
			$oDB->rollback();
			return $output;
		}

		// Remove all extra variables
		if(Context::get('act')!='procFileDelete')
		{
			$this->deleteDocumentExtraVars($source_obj->get('module_srl'), $obj->document_srl, null, Context::getLangType());
			// Insert extra variables if the document successfully inserted.
			$extra_keys = $oDocumentModel->getExtraKeys($obj->module_srl);
			if(count($extra_keys))
			{
				foreach($extra_keys as $idx => $extra_item)
				{
					$value = NULL;
					if(isset($obj->{'extra_vars'.$idx}))
					{
						$tmp = $obj->{'extra_vars'.$idx};
						if(is_array($tmp))
							$value = implode('|@|', $tmp);
						else
							$value = trim($tmp);
					}
					else if(isset($obj->{$extra_item->name})) $value = trim($obj->{$extra_item->name});
					if($value == NULL) continue;
					$this->insertDocumentExtraVar($obj->module_srl, $obj->document_srl, $idx, $value, $extra_item->eid);
				}
			}
			// Inert extra vars for multi-language support of title and contents.
			if($extra_content->title) $this->insertDocumentExtraVar($obj->module_srl, $obj->document_srl, -1, $extra_content->title, 'title_'.Context::getLangType());
			if($extra_content->content) $this->insertDocumentExtraVar($obj->module_srl, $obj->document_srl, -2, $extra_content->content, 'content_'.Context::getLangType());
		}

		// Call a trigger (after)
		if($output->toBool())
		{
			$trigger_output = ModuleHandler::triggerCall('document.updateDocument', 'after', $obj);
			if(!$trigger_output->toBool())
			{
				$oDB->rollback();
				return $trigger_output;
			}
		}

		// commit
		$oDB->commit();
		// Remove the thumbnail file
		FileHandler::removeDir(sprintf('files/thumbnails/%s',getNumberingPath($obj->document_srl, 3)));

		$output->add('document_srl',$obj->document_srl);
		//remove from cache
		$oCacheHandler = CacheHandler::getInstance('object');
		if($oCacheHandler->isSupport())
		{
			//remove document item from cache
			$cache_key = 'document_item:'. getNumberingPath($obj->document_srl) . $obj->document_srl;
			$oCacheHandler->delete($cache_key);
		}

		return $output;
	}

	/**
	 * For old version, comment allow status check.
	 * @param object $obj
	 * @return void
	 */
	function _checkCommentStatusForOldVersion(&$obj)
	{
		if(!isset($obj->allow_comment)) $obj->allow_comment = 'N';
		if(!isset($obj->lock_comment)) $obj->lock_comment = 'N';

		if($obj->allow_comment == 'Y' && $obj->lock_comment == 'N') $obj->commentStatus = 'ALLOW';
		else $obj->commentStatus = 'DENY';
	}


	/**
	 * Return default status
	 * @return string
	 */
	function getDefaultStatus()
	{
		return $this->statusList['public'];
	}


	/**
	 * Return status by key
	 * @return string
	 */
	function getConfigStatus($key)
	{
		if(array_key_exists(strtolower($key), $this->statusList)) return $this->statusList[$key];
		else $this->getDefaultStatus();
	}

		/**
	 * Remove values of extra variable from the document
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param int $var_idx
	 * @param string $lang_code
	 * @param int $eid
	 * @return $output
	 */
	function deleteDocumentExtraVars($module_srl, $document_srl = null, $var_idx = null, $lang_code = null, $eid = null)
	{
		$obj = new stdClass();
		$obj->module_srl = $module_srl;
		if(!is_null($document_srl)) $obj->document_srl = $document_srl;
		if(!is_null($var_idx)) $obj->var_idx = $var_idx;
		if(!is_null($lang_code)) $obj->lang_code = $lang_code;
		if(!is_null($eid)) $obj->eid = $eid;
		$output = executeQuery('document.deleteDocumentExtraVars', $obj);
		return $output;
	}


		/**
	 * Insert extra vaiable to the documents table
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param int $var_idx
	 * @param mixed $value
	 * @param int $eid
	 * @param string $lang_code
	 * @return BaseObject|void
	 */
	function insertDocumentExtraVar($module_srl, $document_srl, $var_idx, $value, $eid = null, $lang_code = '')
	{
		if(!$module_srl || !$document_srl || !$var_idx || !isset($value)) return new BaseObject(-1,'msg_invalid_request');
		if(!$lang_code) $lang_code = Context::getLangType();

		$obj = new stdClass;
		$obj->module_srl = $module_srl;
		$obj->document_srl = $document_srl;
		$obj->var_idx = $var_idx;
		$obj->value = $value;
		$obj->lang_code = $lang_code;
		$obj->eid = $eid;

		executeQuery('document.insertDocumentExtraVar', $obj);
	}














	/**
     * @brief Preview for wikitext
     * @developer Florin Ercus (xe_dev@arnia.ro)
     * @access public
     * @return
     *
     * Called through AJAX, returns JSON
     */
    function procWikiTextParse()
    {
        require_once "lib/WikiText.class.php";
        $content = Context::get('content');
        $lang = Context::get('markup');
        $this->module_info->markup_type = $lang;
        // $parser = $this->getWikiTextParser();
        // $content = $parser->parse($content, false);
        $rez = array('content'=>$content);
        //@TODO: avoid this by using the default ajax mechanism
        echo json_encode($rez);
        die;
    }

	/**
	 * @brief Checks to see if document was edited by someone else, so that we won't override their changes on save
	 * @developer Corina Udrescu (xe_dev@arnia.ro)
	 * @access public
	 * @return
	 * 
	 * Called through AJAX, returns JSON
	 */
	function procWikiCheckIfDocumentWasUpdated()
	{
		// Force json response
		Context::setRequestMethod('JSON');
		
		$document_srl = Context::get('document_srl');
		if(!$document_srl) 
		{
			$this->add('updated', false);
			return;
		}
		
		$previous_doc_edit = Context::get('latest_doc_edit');
		
		$oDocumentModel = &getModel('document');
		$output = $oDocumentModel->getHistories($document_srl, 1, 1);
		if($output->toBool() && $output->data) // If we did find previous edits
		{
			$history = array_pop($output->data);
			$latest_doc_edit = $history->history_srl;
			if((!$previous_doc_edit && $latest_doc_edit) 
					|| ($previous_doc_edit < $latest_doc_edit))
			{
				$this->add('updated', true);
				return;				
			}

		}		
		
		$this->add('updated', false);
		return;		
	}
	
	/**
	 * @brief Delete database references to links in current document
	 * @developer Corina Udrescu (xe_dev@arnia.ro)
	 * @access public
	 * @param $document_srl 
	 * @return type 
	 */
	function deleteLinkedDocuments($document_srl) 
	{
		
		$args->document_srl = $document_srl; 
		$output = executeQuery('wiki.deleteLinkedDocuments', $args); 
		return $output;
	}
	
	/**
	 * @brief Save all internal links in current document to the database
	 * @developer Corina Udrescu (xe_dev@arnia.ro)
	 * @access public
	 * @param $document_srl
	 * @param $alias_list
	 * @param $module_srl
	 * @return $output 
	 */
	function insertLinkedDocuments($document_srl, $alias_list, $module_srl) 
	{
		$args->document_srl = $document_srl; 
		$args->alias_list = implode(',', $alias_list); 
		$args->module_srl = $module_srl; 
		$output = executeQuery('wiki.insertLinkedDocuments', $args); 
		return $output;
	}
	
	/**
	 * @brief Updates info about links in current document
	 * @developer Corina Udrescu (xe_dev@arnia.ro)
	 * @access public
	 * @param $document_srl
	 * @param $alias_list
	 * @param $module_srl
	 * @return type 
	 */
	function updateLinkedDocuments($document_srl, $alias_list, $module_srl) 
	{
		$output = $this->deleteLinkedDocuments($document_srl);
		if($output->toBool()) 
		{
			$output = $this->insertLinkedDocuments($document_srl, $alias_list, $module_srl); 
		}
		return $output;
	}
	
	/**
	 * @brief Register comments on the wiki if user is not logged
	 * @developer Bogdan Bajanica (xe_dev@arnia.ro)
	 * @access public
	 * @return
	 */
	function procWikiInsertCommentNotLogged() 
	{
		$this->procWikiInsertComment();
	}
	
	/**
	 * @brief Register comments on the wiki
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return
	 */
	function procWikiInsertComment() 
	{
		// Check permissions
		if(!$this->grant->write_comment) 
		{
			return new Object(-1, 'msg_not_permitted');
		}
		
		// extract data required
		$obj = Context::gets('document_srl', 'comment_srl', 'parent_srl', 'content', 'password', 'nick_name', 'nick_name', 'member_srl', 'email_address', 'homepage', 'is_secret', 'notify_message');
		$obj->module_srl = $this->module_srl;
		// Check for the presence of document object
		$oDocumentModel = &getModel('document'); 
		$oDocument = $oDocumentModel->getDocument($obj->document_srl);
		if(!$oDocument->isExists()) 
		{
			return new Object(-1, 'msg_not_permitted');
		}
		// Create object model of document module
		$oCommentModel = &getModel('comment');
		// Create object controller of document module
		$oCommentController = &getController('comment');
		
		// Check for the presence of comment_srl
		// if comment_srl is n/a then retrieves a value with getNextSequence()
		if(!$obj->comment_srl) 
		{
			$obj->comment_srl = getNextSequence();
		}
		else 
		{
			$comment = $oCommentModel->getComment($obj->comment_srl, $this->grant->manager);
		}
		
		// If there is no new comment_srl
		if($comment->comment_srl != $obj->comment_srl) 
		{
			// If there is no new parent_srl	
			if($obj->parent_srl) 
			{
				$parent_comment = $oCommentModel->getComment($obj->parent_srl);
				if(!$parent_comment->comment_srl) 
				{
					return new Object(-1, 'msg_invalid_request');
				}
				$output = $oCommentController->insertComment($obj);
			}
			else 
			{
				$output = $oCommentController->insertComment($obj);
			}
			if($output->toBool()) 
			{
				//check if comment writer is admin or not
				$oMemberModel = &getModel("member");
				if(isset($obj->member_srl) && !is_null($obj->member_srl)) 
				{
					$member_info = $oMemberModel->getMemberInfoByMemberSrl($obj->member_srl);
				}
				else 
				{
					$member_info->is_admin = 'N';
				}
				// if current module is using Comment Approval System and comment write is not admin user then
				if(method_exists($oCommentController,'isModuleUsingPublishValidation') 
						&& $oCommentController->isModuleUsingPublishValidation($this->module_info->module_srl) 
						&& $member_info->is_admin != 'Y') 
				{
					$this->setMessage('comment_to_be_approved');
				}
				else 
				{
					$this->setMessage('success_registed');
				}
			}
		}
		else // If you have to modify comment_srl
		{
			$obj->parent_srl = $comment->parent_srl; 
			$output = $oCommentController->updateComment($obj, $this->grant->manager);
			//$comment_srl = $obj->comment_srl;
		}
		if(!$output->toBool()) 
		{
			return $output; 
		}
		
		$this->add('mid', Context::get('mid')); 
		$this->add('document_srl', $obj->document_srl); 
		$this->add('comment_srl', $obj->comment_srl); 
		$this->setRedirectUrl(Context::get('success_return_url'));
	}
	
	/**
	 * @brief Delete article from the wiki 
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return
	 */
	function procWikiDeleteDocument() 
	{
		$oDocumentController = &getController('document'); 
		$oDocumentModel = &getModel('document');
		
		// Check permissions
		if(!$this->grant->delete_document) 
		{
			return new Object(-1, 'msg_not_permitted'); 
		}
		
		$document_srl = Context::get('document_srl');
		if(!$document_srl) 
		{
			return new Object(-1, 'msg_invalid_request'); 
		}
		
		$oDocument = $oDocumentModel->getDocument($document_srl);
		if(!$oDocument || !$oDocument->isExists()) 
		{
			return new Object(-1, 'msg_invalid_request');
		}
		
		$output = $oDocumentController->deleteDocument($oDocument->document_srl);
		
		if(!$output->toBool()) 
		{
			return $output; 
		}
		$oDocumentController->deleteDocumentAliasByDocument($oDocument->document_srl); 
		$this->recompileTree($this->module_srl); 
		$tree_args->module_srl = $this->module_srl; 
		$tree_args->document_srl = $oDocument->document_srl; 
		$output = executeQuery('wiki.deleteTreeNode', $tree_args);
		// remove document from cache
		$oCacheHandler = CacheHandler::getInstance('object', NULL, TRUE);
		if($oCacheHandler->isSupport()) 
		{
			$object_key = sprintf('%s.%s.php', $document_srl, Context::getLangType()); 
			$cache_key = $oCacheHandler->getGroupKey('wikiContent', $object_key); 
			$oCacheHandler->delete($cache_key);
		}
		$site_module_info = Context::get('site_module_info'); 
		$this->setRedirectUrl(getSiteUrl($site_module_info->domain, '', 'mid', $this->module_info->mid));
	}
	
	/**
	 * @brief Delete comment from the wiki
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return
	 */
	function procWikiDeleteComment() 
	{
		// check the comment's sequence number
		$comment_srl = Context::get('comment_srl');
		if(!$comment_srl) 
		{
			return $this->doError('msg_invalid_request');
		}
		// create controller object of comment module
		$oCommentController = &getController('comment'); 
		$output = $oCommentController->deleteComment($comment_srl, $this->grant->manager);
		if(!$output->toBool()) 
		{
			return $output; 
		}
		
		$this->add('mid', Context::get('mid')); 
		$this->add('page', Context::get('page')); 
		$this->add('document_srl', $output->get('document_srl')); 
		$this->setRedirectUrl(Context::get('success_return_url'));
		//$this->setMessage('success_deleted');
	}
	
	/**
	 * @brief Change position of the document on hierarchy
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return 
	 */
	function procWikiMoveTree()
	{
		// Check permissions
		if(!$this->grant->write_document)
		{
			return new Object(-1, 'msg_not_permitted');
		}
		
		// request arguments
		$args = Context::gets('parent_srl', 'target_srl', 'source_srl');
		// retrieve Node information
		$output = executeQuery('wiki.getTreeNode', $args); 
		$node = $output->data;
		if(!$node->document_srl) 
		{
			return new Object('msg_invalid_request'); 
		}
		$args->module_srl = $node->module_srl; 
		$args->title = $node->title;
		if(!$args->parent_srl) 
		{
			$args->parent_srl = 0;
		}
		
		// target without parent list_order must have a minimum list_order
		if(!$args->target_srl)
		{
			$list_order->parent_srl = $args->parent_srl; 
			$output = executeQuery('wiki.getTreeMinListorder', $list_order);
			if($output->data->list_order) 
			{
				$args->list_order = $output->data->list_order - 1;
			}
		}
		else 
		{
			$t_args->source_srl = $args->target_srl; 
			$output = executeQuery('wiki.getTreeNode', $t_args); 
			$target = $output->data;

			if(!$target->parent_srl)
			{
				$delete_args = new stdClass;
				$delete_args->document_srl = $args->target_srl;
				$delete_args->module_srl = $target->module_srl;

				$output = executeQuery('wiki.deleteTreeNode', $delete_args);
			}
			else
			{
				$update_args = new stdClass;
				$update_args->module_srl = $target->module_srl;
				$update_args->parent_srl = $target->parent_srl;
				$update_args->list_order = $target->list_order;

				$output = executeQuery('wiki.updateTreeListOrder', $update_args);
			}

			if(!$output->toBool()) 
			{
				return $output;
			}

			$args->list_order = $target->list_order + 1;
		}
		if(!$node->is_exists) 
		{
			$output = executeQuery('wiki.insertTreeNode', $args);
		}
		else 
		{
			$output = executeQuery('wiki.updateTreeNode', $args);
		}
		if(!$output->toBool()) 
		{
			return $output;
		}
		if($args->list_order) 
		{
			$doc->document_srl = $args->source_srl; 
			$doc->list_order = $args->list_order; 
			$output = executeQuery('wiki.updateDocumentListOrder', $doc);
			if(!$output->toBool()) 
			{
				return $output;
			}
		}
		$this->recompileTree($this->module_srl);
	}
	
	/**
	 * @brief recreate Wiki the hierarchy
	 * @developer NHN (developers@xpressengine.com)	
	 * @access public
	 * @return
	 */
	function procWikiRecompileTree() 
	{
		if(!$this->grant->write_document) 
		{
			return new Object(-1, 'msg_not_permitted'); 
		}
		return $this->recompileTree($this->module_srl);
	}
	
	/**
	 * @brief recreate Wiki hierarchy
	 * @developer NHN (developers@xpressengine.com)	
	 * @access public
	 * @param $module_srl
	 * @return
	 */	
	function recompileTree($module_srl) 
	{
		$oWikiModel = &getModel('wiki'); 
		$list = $oWikiModel->loadWikiTreeList($module_srl); 
		$dat_file = sprintf('%sfiles/cache/wiki/%d.dat', _XE_PATH_, $module_srl); 
		$xml_file = sprintf('%sfiles/cache/wiki/%d.xml', _XE_PATH_, $module_srl); 
		$buff = ''; $xml_buff = "<root>\n";
		
		// cache file creation
		foreach($list as $key => $val) 
		{
			$buff .= sprintf('%d,%d,%d,%d,%s%s', $val->parent_srl, $val->document_srl, $val->depth, $val->childs, $val->title, "\n"); 
			$xml_buff .= sprintf('<node node_srl="%d" parent_srl="%d"><![CDATA[%s]]></node>%s', $val->document_srl, $val->parent_srl, $val->title, "\n");
		}
		$xml_buff .= '</root>';
		
		FileHandler::writeFile($dat_file, $buff); 
		FileHandler::writeFile($xml_file, $xml_buff); 
		return new Object();
	}
	
	/**
	 * @brief Confirm password for modifying non-members Comments
	 * @developer NHN (developers@xpressengine.com)
	 * @access public
	 * @return
	 */
	function procWikiVerificationPassword() 
	{
		$password = Context::get('password');
		$document_srl = Context::get('document_srl');
		$comment_srl = Context::get('comment_srl'); 
		
		$oMemberModel = &getModel('member');
		if($comment_srl) 
		{
			$oCommentModel = &getModel('comment'); 
			$oComment = $oCommentModel->getComment($comment_srl);
			if(!$oComment->isExists()) 
			{
				return new Object(-1, 'msg_invalid_request');
			}
			if(!$oMemberModel->isValidPassword($oComment->get('password'), $password)) 
			{
					return new Object(-1, 'msg_invalid_password'); 
			}
			$oComment->setGrant();
		} else {
			// get the document information
			$oDocumentModel = &getModel('document');
			$oDocument = $oDocumentModel->getDocument($document_srl);
			if(!$oDocument->isExists()) return new Object(-1, 'msg_invalid_request');

			// compare the document password and the user input password
			if(!$oMemberModel->isValidPassword($oDocument->get('password'),$password)) return new Object(-1, 'msg_invalid_password');

			$oDocument->setGrant();

			$succes_return_url = Context::get('success_return_url');
			$this->setRedirectUrl($succes_return_url);

		}
	}
	
	/**
	 * @brief function, used by Ajax call, that return curent version and one of history version of the document for making diff
	 * @developer Bogdan Bajanica (xe_dev@arnia.ro)
	 * @access public
	 * @return
	 */
	function procWikiContentDiff() 
	{
		$document_srl = Context::get("document_srl"); 
		$history_srl = Context::get("history_srl"); 
		$oDocumentModel = &getModel('document'); 
		$oDocument = $oDocumentModel->getDocument($document_srl); 
		$current_content = $oDocument->get('content'); 
		$history = $oDocumentModel->getHistory($history_srl);
		$history_content = $history->content; 
		$this->add('old', $history_content); 
		$this->add('current', $current_content);
	}
	
	/**
	 * @brief function, used by Ajax call, that return HTML Comment Editor
	 * @developer Bogdan Bajanica (xe_dev@arnia.ro)
	 * @access public
	 * @return
	 */
	function procDispCommentEditor() 
	{
		$document_srl = Context::get("document_srl"); 
		$oDocumentModel = &getModel('document'); 
		$oDocument = $oDocumentModel->getDocument($document_srl); 
		$editor = $oDocument->getCommentEditor(); 
		$oEditorModel = &getModel('editor');
		
		// get an editor
		$option->primary_key_name = 'comment_srl'; 
		$option->content_key_name = 'content'; 
		$option->allow_fileupload = FALSE; 
		$option->enable_autosave = FALSE; 
		$option->disable_html = TRUE; 
		$option->enable_default_component = FALSE; 
		$option->enable_component = FALSE; 
		$option->resizable = TRUE; 
		$option->height = 150; 
		$editor = $oEditorModel->getEditor(0, $option); 
		
		Context::set('editor', $editor); 
		$this->add('editor', $editor);
	}
}
/* End of file wiki.controller.php */
/* Location: wiki.controller.php */
