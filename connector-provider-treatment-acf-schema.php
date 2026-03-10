<?php
/**
 * Plugin Name: Dermatology EEAT Master Authority Schema
 * Description: Baseline 2.3 - 100% Feature Retention + Global Entity Type Fix for worksFor.
 * Author: DIGITAL ATLAS + GEMINI AI 
 * Version: 2.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- 1. Options Page Initialization ---
add_action('acf/init', function() {
    if( function_exists('acf_add_options_page') ) {
        acf_add_options_page(array(
            'page_title'    => 'Clinic Schema Settings',
            'menu_title'    => 'Clinic Schema',
            'menu_slug'     => 'clinic-schema-settings',
            'capability'    => 'edit_posts',
            'redirect'      => false,
            'icon_url'      => 'dashicons-admin-multisite'
        ));
    }
});

add_action( 'wp_head', 'gd_inject_eeat_schema' );

function gd_inject_eeat_schema() {
    $post_id = get_the_ID();
    
    // --- 2. MULTI-LOCATION DISCOVERY ---
    $locations = get_field('clinic_locations', 'option');
    $primary_location = null;
    $current_page_location = null;

    if ( is_array($locations) ) {
        foreach ( $locations as $loc ) {
            if ( !empty($loc['is_primary']) ) {
                $primary_location = $loc;
            }
            if ( is_array($loc['location_pages']) && in_array($post_id, $loc['location_pages']) ) {
                $current_page_location = $loc;
            }
        }
    }

    if ( is_front_page() && $primary_location ) {
        $current_page_location = $primary_location;
    }

    // --- 3. GLOBAL ENTITY LOGIC (Restored & Fixed) ---
    // Moved to global scope to prevent null errors in worksFor
    $base_url = get_home_url();
    $primary_fragment = $primary_location['location_fragment'] ?? '#main-clinic';
    $clinic_id = $base_url . $primary_fragment;
    
    // Define the Authoritative Entity Type for use in worksFor and Providers
    $entity_type = "MedicalOrganization"; 

    // --- 4. FORK: TREATMENT POST TYPE ---
    if ( is_singular( array( 'treatment', 'service' ) ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_procedure = in_array( 'procedure', $categories );
        $is_condition   = in_array( 'condition', $categories );
        $linked_providers = get_field('treatment_providers', $post_id);
        $clinical_name = get_field('clinical_name', $post_id);

        $treatment_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalWebPage",
            "name" =>  $clinical_name ?: get_the_title($post_id),
            "description" => get_field('procedure_description', $post_id) ?: get_field('condition_description', $post_id),
            "lastReviewed" => get_the_modified_date('c', $post_id),
            "mainEntity" => []
        ];

        // FORK A: PROCEDURE
        if ( $is_procedure ) {
            $body_locations = get_field('procedure_body_location', $post_id);
            $procedure_entity = [
                "@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", 
                "@id" => get_permalink($post_id) . "#procedure", 
                "name" => $clinical_name ?: get_the_title($post_id),
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
                "provider" => ["@type" => $entity_type, "@id" => $clinic_id]
            ];
            if ( is_array($body_locations) ) $procedure_entity["bodyLocation"] = $body_locations;
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $alts)));
            $treatment_schema["mainEntity"][] = $procedure_entity;
        }

// --- FORK B: CONDITION ---
        elseif ( $is_condition ) {
            $raw_anatomy = get_field('condition_associated_anatomy', $post_id);
            $anatomy_list = [];
            if ( !empty($raw_anatomy) ) {
                foreach (array_filter(array_map('trim', explode("\n", $raw_anatomy))) as $line) { 
                    $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => $line]; 
                }
            } else { 
                $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => "Skin"]; 
            }

            $condition_node = [
                "@type" => "MedicalCondition", 
                "@id" => get_permalink($post_id) . "#condition", 
                "name" => $clinical_name ?: get_the_title($post_id), 
                "url" => get_permalink($post_id), 
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"], 
                "associatedAnatomy" => $anatomy_list, 
                "epidemiology" => get_field('condition_epidemiology', $post_id) ?: null, 
                "riskFactor" => get_field('condition_risk_factor', $post_id) ?: null, 
                "differentialDiagnosis" => get_field('condition_differential_diagnosis', $post_id) ?: null,
                "signOrSymptom" => [],
                "possibleTreatment" => []
            ];
            
            // 1. Symptoms
            $symptoms = get_field('condition_symptoms', $post_id);
            if ( is_array($symptoms) ) { 
                foreach ($symptoms as $s) { 
                    if (!empty($s['symptom_name'])) $condition_node["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s['symptom_name']]; 
                } 
            }

            // 2. Possible Treatments (Relationship with Manual Fallback)
            $treatments_list = get_field('possible_treatments_repeater', $post_id);
            if ( is_array($treatments_list) ) {
                foreach ($treatments_list as $t_row) {
                    $t_ids = $t_row['internal_treatment'] ?? null;
                    
                    if ( !empty($t_ids) && is_array($t_ids) ) {
                        // Relationship field is populated
                        foreach ($t_ids as $t_item) {
                            $t_id = (is_object($t_item)) ? $t_item->ID : (is_numeric($t_item) ? $t_item : 0);
                            if ($t_id > 0) {
                                $condition_node["possibleTreatment"][] = [
                                    "@type" => "MedicalProcedure", 
                                    "name" => get_the_title($t_id), 
                                    "description" => get_field("procedure_description", $t_id) ?: null, 
                                    "url" => get_permalink($t_id)
                                ];
                            }
                        }
                    } elseif ( !empty($t_row['internal_treatment_manual']) ) {
                        // Fallback to manual text field
                        $condition_node["possibleTreatment"][] = [
                            "@type" => "MedicalProcedure", 
                            "name" => $t_row['internal_treatment_manual']
                        ];
                    }
                }
            }

            // 3. SameAs (Source of Truth)
            $sources = get_field('source_of_truth', $post_id);
            if ( is_array($sources) ) { 
                foreach ($sources as $s) { 
                    if (!empty($s['same_as'])) $condition_node["sameAs"][] = $s['same_as']; 
                } 
            }
            
            // 4. Alternate Names
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) $condition_node["alternateName"] = array_filter(array_map('trim', explode("\n", $alts)));

            // Clean up empty arrays
            if ( empty($condition_node["signOrSymptom"]) ) unset($condition_node["signOrSymptom"]);
            if ( empty($condition_node["possibleTreatment"]) ) unset($condition_node["possibleTreatment"]);

            if ( is_array($linked_providers) && !empty($linked_providers) ) {
                $rev_id = $linked_providers[0]; 
                $condition_node["reviewedBy"] = ["@type" => get_field('provider_type', $rev_id) ?: 'Person', "name"  => get_the_title($rev_id), "url" => get_permalink($rev_id)];
            }
            $treatment_schema["mainEntity"][] = $condition_node;
        }        

// --- SHARED PROVIDERS BLOCK (Restored Honorifics & worksFor) ---
        $treatment_schema["provider"][] = ["@type" => $entity_type, "@id" => $clinic_id];

        if ( is_array($linked_providers) ) {
            foreach ($linked_providers as $p_id) { 
                $p_t = get_field('provider_type', $p_id) ?: 'Person'; 
                $npi = get_field('provider_npi', $p_id); 
                
                // Explicitly pull honorifics using confirmed ACF names
                $prefix = get_field('honorific_prefix', $p_id); 
                $suffix = get_field('honorific_suffix', $p_id); 

                $p_o = [
                    "@type" => $p_t, 
                    "name" => get_the_title($p_id), 
                    "url" => get_permalink($p_id),
                    "worksFor" => ["@type" => $entity_type, "@id" => $clinic_id]
                ]; 

                // Inject if populated
                if (!empty($prefix)) $p_o["honorificPrefix"] = $prefix; 
                if (!empty($suffix)) $p_o["honorificSuffix"] = $suffix;

                if (!empty($npi)) { 
                    if ($p_t === 'Physician') $p_o["usNPI"] = $npi; 
                    else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; 
                } 
                $treatment_schema["provider"][] = $p_o; 
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }


 // --- PROTECTED PROVIDER PAGES (Team) ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person'; 
        $npi = get_field('provider_npi', $post_id);
        
        // Restore Honorifics for individual Team pages
        $prefix = get_field('honorific_prefix', $post_id);
        $suffix = get_field('honorific_suffix', $post_id);

        $provider_entity = [
            "@type" => ["Person", $p_type], 
            "@id" => get_permalink($post_id) . "#provider", 
            "name" => get_the_title($post_id), 
            "jobTitle" => get_field('exact_job_title', $post_id), 
            "url" => get_permalink($post_id), 
            "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], 
            "worksFor" => [
                "@type" => $entity_type, 
                "@id" => $clinic_id
            ]
        ];

        // Inject Honorifics if populated
        if ( !empty($prefix) ) { $provider_entity["honorificPrefix"] = $prefix; }
        if ( !empty($suffix) ) { $provider_entity["honorificSuffix"] = $suffix; }
        
        // --- Educational & Occupational Credentials ---
        $credentials = get_field('educational_occupational_credential', $post_id);
        if ( is_array($credentials) ) {
            foreach ( $credentials as $cred ) {
                $c_obj = ["@type" => "EducationalOccupationalCredential", "name" => $cred['name']];
                $provider_entity["hasCredential"][] = $c_obj;
            }
        }

        // --- Education History (AlumniOf) ---
        $edu = get_field('education_history', $post_id); 
        if ( is_array($edu) ) { 
            foreach ($edu as $e) { 
                if ( !empty($e['organization_name']) ) {
                    $provider_entity["alumniOf"][] = [
                        "@type" => "EducationalOrganization", 
                        "name" => $e['organization_name']
                    ]; 
                }
            } 
        }
        
        // --- NPI Identification Logic ---
        if ( !empty($npi) ) { 
            if ( $p_type === 'Physician' ) {
                $provider_entity["usNPI"] = $npi; 
            } else {
                $provider_entity["identifier"] = [
                    "@type" => "PropertyValue", 
                    "name" => "NPI", 
                    "value" => $npi
                ]; 
            }
        }

        echo "\n<script type='application/ld+json'>" . json_encode(["@context" => "https://schema.org", "@graph" => [["@type" => "MedicalWebPage", "name" => get_the_title($post_id)], $provider_entity]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }


    

    // --- 6. SATELLITE / GLOBAL CLINIC INJECTION ---
    if ( $current_page_location ) {
        $loc_id = get_home_url() . ($current_page_location['location_fragment'] ?: '#clinic');
        $is_primary_row = !empty($current_page_location['is_primary']);
        $local_entity_type = $is_primary_row ? "MedicalOrganization" : "MedicalClinic";

        $location_schema = [
            "@context" => "https://schema.org",
            "@type" => $local_entity_type,
            "@id" => $loc_id,
            "name" => $current_page_location['location_name'],
            "telephone" => $current_page_location['phone'],
            "medicalSpecialty" => "Dermatology",
            "isAcceptingNewPatients" => (bool)($current_page_location['is_accepting_new_patients'] ?? true),
            "address" => ["@type" => "PostalAddress", "streetAddress" => $current_page_location['address']['street'] ?? '', "addressLocality" => $current_page_location['address']['city'] ?? '', "addressRegion" => $current_page_location['address']['state'] ?? '', "postalCode" => $current_page_location['address']['zip'] ?? '', "addressCountry" => "US"],
            "geo" => ["@type" => "GeoCoordinates", "latitude" => $current_page_location['geo']['lat'] ?? '', "longitude" => $current_page_location['geo']['long'] ?? ''],
            "openingHoursSpecification" => []
        ];

        // Hours Logic
        if ( !empty($current_page_location['opening_hours']) ) {
            foreach ($current_page_location['opening_hours'] as $h) {
                if (!empty($h['days'])) {
                    $spec = ["@type" => "OpeningHoursSpecification", "dayOfWeek" => $h['days']];
                    if (empty($h['opens']) || empty($h['closes'])) { $spec["opens"] = "00:00"; $spec["closes"] = "00:00"; }
                    else { $spec["opens"] = date("H:i", strtotime($h['opens'])); $spec["closes"] = date("H:i", strtotime($h['closes'])); }
                    $location_schema["openingHoursSpecification"][] = $spec;
                }
            }
        }

        // Holiday Logic
        $holidays = $current_page_location['public_holidays'] ?? null;
        if (is_array($holidays)) {
            foreach ($holidays as $hol) {
                if (!empty($hol['date'])) {
                    $h_spec = ["@type" => "OpeningHoursSpecification", "validFrom" => $hol['date'], "validThrough" => $hol['date']];
                    if (empty($hol['opens']) || empty($hol['closes'])) { $h_spec["opens"] = "00:00"; $h_spec["closes"] = "00:00"; }
                    else { $h_spec["opens"] = date("H:i", strtotime($hol['opens'])); $h_spec["closes"] = date("H:i", strtotime($hol['closes'])); }
                    $location_schema["openingHoursSpecification"][] = $h_spec;
                }
            }
        }

        // Global Settings
        $global_logo = get_field('global_clinic_logo', 'option');
        if (!empty($global_logo)) $location_schema["logo"] = $global_logo;
        $global_sameas_raw = get_field('global_clinic_sameas', 'option');
        if (!empty($global_sameas_raw)) $location_schema["sameAs"] = array_filter(array_map('trim', explode("\n", $global_sameas_raw)));
        $insurance_raw = get_field('global_clinic_insurance', 'option');
        if (!empty($insurance_raw)) $location_schema["paymentAccepted"] = array_filter(array_map('trim', explode("\n", $insurance_raw)));
        
        if ( !$is_primary_row && $primary_location ) {
            $location_schema["parentOrganization"] = ["@type" => "MedicalOrganization", "@id" => $clinic_id, "name" => $primary_location['location_name']];
        }

        echo "\n<script type='application/ld+json'>" . json_encode($location_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}