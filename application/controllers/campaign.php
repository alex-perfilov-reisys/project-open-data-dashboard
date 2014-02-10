<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Campaign extends CI_Controller {

	function __construct()
	{
		parent::__construct();
		
		$this->load->helper('api');		
		
		// Determine the environment we're run from for debugging/output 
		if (php_sapi_name() == 'cli') {   
			if (isset($_SERVER['TERM'])) {   
				$this->environment = 'terminal';  
			} else {   
				$this->environment = 'cron';
			}   
		} else { 
			$this->environment = 'server';
		}
	   	
				
	}


	/**
	 * Index Page for this controller.
	 *
	 * Maps to the following URL
	 * 		http://example.com/index.php/welcome
	 *	- or -  
	 * 		http://example.com/index.php/welcome/index
	 *	- or -
	 * Since this controller is set as the default controller in 
	 * config/routes.php, it's displayed at http://example.com/
	 *
	 * So any other public methods not prefixed with an underscore will
	 * map to /index.php/welcome/<method_name>
	 * @see http://codeigniter.com/user_guide/general/urls.html
	 */
	public function index()
	{
		
	
	}
	
	public function convert() {
		$this->load->model('campaign_model', 'campaign');			
				
		$orgs = $this->input->get('orgs', TRUE);
		$geospatial = $this->input->get('geospatial', TRUE);


		$row_total = 100;
		$row_count = 0;
		
		$row_pagesize = 100;
		$raw_data = array();        	        
		        
		while($row_count < $row_total) {
			$result 	= $this->campaign->get_datagov_json($orgs, $geospatial, $row_pagesize, $row_count, true);
			
			if(!empty($result)) {
				$row_total = $result->result->count;
				$row_count = $row_count + $row_pagesize; 

				$raw_data = array_merge($raw_data, $result->result->results);				
			} else {
				break;
			}
			
		}		        
		        		
		if(!empty($raw_data)) {
		
			$json_schema = $this->campaign->datajson_schema();
			$datajson_model = $this->campaign->schema_to_model($json_schema->properties);						

			$convert = array();
			foreach ($raw_data as $ckan_data) {
				$model = clone $datajson_model;						
				$convert[] = $this->campaign->datajson_crosswalk($ckan_data, $model);
			}		
			
		    header('Content-type: application/json');
		    print json_encode($convert);		
			exit;			
			
		} else {
			return false;
		}

				

		
	}


	public function csv($orgs = null) {
		$this->load->model('campaign_model', 'campaign');			
		
		if($orgs == 'all') {
		    $orgs = '*';
		}
		
		if(empty($orgs)) {
		    $orgs = $this->input->get('orgs', TRUE);		    
		}	
		
		if(empty($orgs)) {
		    $geospatial = $this->input->get('geospatial', TRUE);
		} else {
		    $geospatial = false;
		}		
		
        // if we didn't get any requests, bail
        if(empty($orgs)) {
    		show_404($orgs, false);
    		exit;
        }

		$row_total = 100;
		$row_count = 0;
		
		$row_pagesize = 500;
		$raw_data = array();
		
		while($row_count < $row_total) {
			$result 	= $this->campaign->get_datagov_json($orgs, $geospatial, $row_pagesize, $row_count, true);
			
			if(!empty($result)) {
				$row_total = $result->result->count;
				$row_count = $row_count + $row_pagesize; 

                if ($this->environment == 'terminal') {
                    echo 'Exporting ' . $row_count . ' of ' . $row_total .  PHP_EOL;					
                }

				$raw_data = array_merge($raw_data, $result->result->results);				
			} else {
				break;
			}
			
		}

        // if we didn't get any data, bail
		if(empty($raw_data)) {
		    show_404($orgs, false);
		    exit;
		}
		
				
		// Create a stream opening it with read / write mode
		$stream = fopen('data://text/plain,' . "", 'w+');				
			
		// use data.json model
		$json_schema = $this->campaign->datajson_schema();
		$datajson_model = $this->campaign->schema_to_model($json_schema->properties);			
						
		$csv_rows = array();	
		foreach ($raw_data as $ckan_data) {
		    		    
            $special_extras = $this->special_extras($ckan_data);

			$model      = clone $datajson_model;								    		    
		    $csv_row    = $this->campaign->datajson_crosswalk($ckan_data, $model);

            $csv_row->accessURL  = array();
            $csv_row->format     = array();            
            foreach ($csv_row->distribution as $distribution) {
                $csv_row->accessURL[]   = $distribution->accessURL;
                $csv_row->format[]      = $distribution->format;                
            }
		    
    		foreach ($csv_row as $key => $value) {

    			if(empty($value) OR is_object($value) == true OR (is_array($value) == true && !empty($value[0]) && is_object($value[0]) == true)) {
    			    $csv_row->$key = null;
    			}
    			    			        			
    			if(is_array($value) == true && !empty($value[0]) && is_object($value[0]) == false) {
    			    $csv_row->$key = implode(',', $value);
    			} 
    			

    		}	
    		
    		$csv_row->_extra_catalog_url                = 'http://catalog.data.gov/dataset/' . $csv_row->identifier; 
            $csv_row->_extra_communities                = $special_extras->groups;
            $csv_row->_extra_communities_categories     = $special_extras->group_categories;            
            
			$csv_rows[] = (array) $csv_row;		    
		}
		
	   //header('Content-type: application/json');
	   //print json_encode($csv_rows);		
	   //exit;		
		
		
		$headings = array_keys($csv_rows[0]);		
		
		// Open the output stream
        if ($this->environment == 'terminal') {
            $filepath = realpath('./csv/output.csv');
            $fh = fopen($filepath, 'w');
            echo 'Attempting to save csv to ' . $filepath .  PHP_EOL;					            
        } else {
            $fh = fopen('php://output', 'w');
        }		
		
		
		// Start output buffering (to capture stream contents)
		ob_start();
		fputcsv($fh, $headings);
		
		// Loop over the * to export
		if (!empty($csv_rows)) {
			foreach ($csv_rows as $row) {
				fputcsv($fh, $row);
			}
		}
		
        if ($this->environment !== 'terminal') {
    		// Get the contents of the output buffer
    		$string = ob_get_clean();
    		$filename = 'csv_' . date('Ymd') .'_' . date('His');
    		// Output CSV-specific headers

    		header("Pragma: public");
    		header("Expires: 0");
    		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    		header("Cache-Control: private",false);
    		header('Content-type: text/csv');		
    		header("Content-Disposition: attachment; filename=\"$filename.csv\";" );
    		header("Content-Transfer-Encoding: binary");

    		exit($string);        
        } else {
            echo 'Done' . PHP_EOL;					            
            exit;
        }
		

		
	}


    private function special_extras($ckan_data) {
        
        $special_extras = new stdClass();
        
	    // communities
	    $groups = array();		        
	    if(!empty($ckan_data->groups)) {
	        foreach ($ckan_data->groups as $group) {
	            if(!empty($group->title)) {
	                $groups[] = $group->title;
	                $groups_id[] = $group->id;
	            }		            
	        }		        
	    }
	    $special_extras->groups = (!empty($groups)) ? implode(',', $groups) : null;		    

	    // community categories
	    $group_categories = array();		        
	    if(!empty($groups_id)) {
	        foreach ($groups_id as $group_id) {
	            $group_category_id = '__category_tag_' . $group_id;
	            
	            if(!empty($ckan_data->extras)) {
	                foreach($ckan_data->extras as $extra) {

	                    if ($extra->key == $group_category_id) {
	                        $categories = json_decode($extra->value);
	                        if(is_array($categories)) {
	                            foreach($categories as $category_name) {
	                                $group_categories[$category_name] = true;
	                            }
	                        }
	                        
	                    }
	                }
	                
	            }		            
	        }		        
	    }
	    if(!empty($group_categories)) {
	        $group_categories = array_keys($group_categories);
	        $special_extras->group_categories = implode(',', $group_categories);
	    } else {
	        $special_extras->group_categories = null;	        
	    }	    


	    // formats
        $formats = array();		        		    
	    if(!empty($ckan_data->resources)) {
	        foreach ($ckan_data->resources as $resource) {
	            if(!empty($resource->format)) {		            
	                $formats[] = (string) $resource->format;
                }
	        }		        
	    }
	    $special_extras->formats = (!empty($formats)) ? implode(',', $formats) : null;        
        
        
        return $special_extras;
    }
    
    
    
	public function digitalstrategy($id = null) {
		
		
		$this->load->model('campaign_model', 'campaign');   
		
		$this->db->select('*');	
		$this->db->from('offices');			
		$this->db->join('datagov_campaign', 'datagov_campaign.office_id = offices.id', 'left');	
		$this->db->where('offices.cfo_act_agency', 'true');	
		$this->db->where('offices.no_parent', 'true');	
		
		if(!empty($id) && $id != 'all') {
    		$this->db->where('offices.id', $id);					    
		}		
				
		$this->db->order_by("offices.name", "asc"); 			
		$query = $this->db->get();
        
		if ($query->num_rows() > 0) {		
			$view_data['digitalstrategy'] = $query->result();
			$query->free_result();
			
			$this->load->view('digitalstrategy', $view_data);	    		
		} else {
    		show_404('digitalgov', false);
		}
		
		
	}    
    
    

    /*
    $component can be datajson, datapage, digitalstrategy
    */
	public function status($id = null, $component = null) {
		
		// enforce explicit component selection
		if(empty($component)) {
		    show_404('status', false);    		
		}		
				
		$this->load->model('campaign_model', 'campaign');			
				
		$this->db->select('url, id');
		
		if($id != 'all') {
    		$this->db->where('id', $id);					    
		}
		
				
		$query = $this->db->get('offices');
		
		if ($query->num_rows() > 0) {
		   	$offices = $query->result();
		
			foreach ($offices as $office) {
				
				// initialize update object  

				$update = $this->campaign->datagov_office($office->id);

    			if(!$update){
    				$update = $this->campaign->datagov_model();				
					$update->office_id = $office->id;    									
    			}
	
				$url =  parse_url($office->url);
				$url = $url['scheme'] . '://' . $url['host'];
			
			
			
                /*
                ################ datajson ################
                */			
			
			    if ($component == 'all' || $component == 'datajson' || $component == 'datajson-refresh') {
			        			        
    				$expected_datajson_url = $url . '/data.json';
				
    				// attempt to break any caching
    				$expected_datajson_url_refresh = $expected_datajson_url . '?refresh=' . time();

    				if ($this->environment == 'terminal') {
    					echo 'Attempting to request ' . $expected_datajson_url . ' and ' . $expected_datajson_url_refresh . PHP_EOL;
    				}
                
                    // Try to force refresh the cache, follow redirects and get headers
        		    $json_refresh = true;
            		$status = $this->campaign->uri_header($expected_datajson_url_refresh);
        		
            		if(!$status OR $status['http_code'] != 200) {
            		    $json_refresh = false;
            		    $status = $this->campaign->uri_header($expected_datajson_url);
            		}
            		$status['url']          = $expected_datajson_url;
            		$status['expected_url'] = $expected_datajson_url;


            		$reload = true;

            		// Check to see if the file has been updated since last time
            		if( !empty($status['filetime']) && $component !== 'datajson-refresh') {
            			if ($old_status = json_decode($update->datajson_status)) {
            				if ($status['filetime'] == $old_status->filetime) {
            					$reload = false;

			    				if ($this->environment == 'terminal') {
			    					echo 'Nothing to update for ' . $update->office_id . ' on ' . $status['url'] . PHP_EOL . PHP_EOL;
			    				}

            				}
            			}
            		}

            		if($reload) {

	                    // Save current update status in case things break during json_status 
	    				$update->datajson_status = (!empty($status)) ? json_encode($status) : null; 
					
	    				if ($this->environment == 'terminal') {
	    					echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datajson_status . PHP_EOL . PHP_EOL;
	    				}				
					
	    				$this->campaign->update_status($update);
					           

	                    // Check JSON status
	                    $real_url 				= ($json_refresh) ? $expected_datajson_url_refresh : $expected_datajson_url;
	                    $status 				= $this->json_status($status, $real_url);
	            		$status['url']          = $expected_datajson_url;
	            		$status['expected_url'] = $expected_datajson_url;   
						$status['last_crawl']	= mktime();

	    				$update->datajson_status = (!empty($status)) ? json_encode($status) : null; 
	    				$update->datajson_errors = (!empty($status) && !empty($status['schema_errors'])) ? json_encode($status['schema_errors']) : null;				
	    				if(!empty($status) && !empty($status['schema_errors'])) unset($status['schema_errors']);                
	                
	                
	    				if ($this->environment == 'terminal') {
	    					echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datajson_status . PHP_EOL . PHP_EOL;
	    				}                
	                
	                    $this->campaign->update_status($update);

            		}

				
				}


                /*
                ################ datapage ################
                */
                
               if ($component == 'all' || $component == 'datapage') {
			    
                
                    // Get status of html /data page				
    				$page_status_url = $url . '/data';
				
    				if ($this->environment == 'terminal') {
    					echo 'Attempting to request ' . $page_status_url . PHP_EOL;
    				}				

            		$page_status = $this->campaign->uri_header($page_status_url);
            		$page_status['expected_url'] = $page_status_url;

    				$update->datapage_status = (!empty($page_status)) ? json_encode($page_status) : null;
				
    				if ($this->environment == 'terminal') {
    					echo 'Attempting to set ' . $update->office_id . ' with ' . $update->datapage_status . PHP_EOL . PHP_EOL;
    				}				
				
    							
    				$this->campaign->update_status($update);
				
			    }
			    
			    
                 /*
                 ################ digitalstrategy ################
                 */

                if ($component == 'all' || $component == 'digitalstrategy') {


                     // Get status of html /data page				
     				$digitalstrategy_status_url = $url . '/digitalstrategy.json';

     				if ($this->environment == 'terminal') {
     					echo 'Attempting to request ' . $digitalstrategy_status_url . PHP_EOL;
     				}				

             		$page_status = $this->campaign->uri_header($digitalstrategy_status_url);
             		$page_status['expected_url'] = $digitalstrategy_status_url;

     				$update->digitalstrategy_status = (!empty($page_status)) ? json_encode($page_status) : null;

     				if ($this->environment == 'terminal') {
     					echo 'Attempting to set ' . $update->office_id . ' with ' . $update->digitalstrategy_status . PHP_EOL . PHP_EOL;
     				}				

     				$this->campaign->update_status($update);

 			    }			    
			    
			    
								
        		if(!empty($id) && $this->environment != 'terminal') {			
        		    $this->load->helper('url');
                    redirect('/offices/detail/' . $id, 'location');
                }
				
			}
		
		
		
		}		
        
	}
	
	public function json_status($status, $real_url = null) {

        // if this isn't an array, assume it's a urlencoded URI
        if(is_string($status)) {
            $this->load->model('campaign_model', 'campaign');

            $expected_datajson_url = urldecode($status);
            
       		$status = $this->campaign->uri_header($expected_datajson_url);
        	$status['url'] = $expected_datajson_url;            
        }

        $status['url'] = (!empty($real_url)) ? $real_url : $status['url'];

		if($status['http_code'] == 200) {
		    
			$validation = $this->campaign->validate_datajson($status['url']);
            //var_dump($validation); exit;
			if(!empty($validation)) {
				$status['valid_json'] = true;
				$status['valid_schema'] = $validation['valid'];
				$status['schema_errors'] = $validation['errors'];	
			} else {
				// data.json was not valid json
				$status['valid_json'] = false;
			}		        
			
		}	
			
		return $status;	    
	}


	public function validate($datajson_url = null, $datajson = null, $headers = null) {

        $this->load->model('campaign_model', 'campaign');		
		
		$datajson 		= ($this->input->post('datajson', TRUE)) ? $this->input->post('datajson', TRUE) : $datajson;
		$datajson_url 	= ($this->input->get_post('datajson_url', TRUE)) ? $this->input->get_post('datajson_url', TRUE) : $datajson_url;

		if($datajson OR $datajson_url) {
			$validation = $this->campaign->validate_datajson($datajson_url, $datajson, $headers);
		}

		if(!empty($validation)) {

	     	header('Content-type: application/json');
	        print json_encode($validation);
	        exit;

		} else {
			$this->load->view('validate');	    		
        } 		

	}



	/*
	Crawl each record in a datajson file and save current version + validation results
	*/
	public function version_datajson($office_id = null) {

        
        $this->load->model('campaign_model', 'campaign');


		// look up last crawl cycle for this office id
        if(!empty($office_id)) {

        	$current_crawl = $this->campaign->datajson_crawl();
        	$current_crawl->office_id = $office_id;

        	if($last_crawl = $this->campaign->get_datajson_crawl($current_crawl->office_id)) {

        		// make sure last crawl completed
        		if ($last_crawl->crawl_status == 'completed' && !empty($last_crawl->crawl_end)) {
        			$current_crawl->crawl_cycle = $last_crawl->crawl_cycle + 1;	
        		} else {
        			// abort
        			$current_crawl->crawl_cycle = $last_crawl->crawl_cycle;
        			$current_crawl->crawl_status = 'aborted';

        			// save crawl status
        			$this->campaign->save_datajson_crawl($current_crawl);

        			return $current_crawl;

        		}
        		
        	} else {
        		$last_crawl = false;
        		$current_crawl->crawl_cycle = 1;        		
        	}


    		$current_crawl->crawl_status = 'started';

			// save crawl status
			$this->campaign->save_datajson_crawl($current_crawl);




        	if ($current_crawl->crawl_status == 'started') {

    			// check to see if datajson status is good enough to parse

    			// ******** missing code here

    			foreach ($metadata_records as $metadata_record) {
    				$this->version_metadata_record($current_crawl);	
    			}

    			// save crawl status
    			$this->campaign->save_datajson_crawl($current_crawl);

    			return $current_crawl;

        	}


        	

        }


	}
	

}