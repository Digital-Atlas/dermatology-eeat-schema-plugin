<?php
/**
 * Plugin Name: Dermatology EEAT Master Schema
 * Description: Baseline 8.0 - Full Restoration + Multi-Location Hub + parentOrganization + Clinic Provider Sync.
 * Version: 18.0
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
            'icon_url'      => 'dashicons-hospital'
        ));
    }
});

add_action( 'wp_head', 'gd_inject_eeat_schema' );

function gd_inject_eeat_schema() {
    $post_id = get_the_ID();
    
    // --- MULTI-LOCATION DISCOVERY ---
    $locations = get_field('clinic_locations', 'option');
    $primary_location = null;
    $current_page_location = null;

    if ( is_array($locations) ) {
        foreach ( $locations as $loc ) {
            // Identify Primary for global @id
            if ( !empty($loc['is_primary']) ) {
                $primary_location = $loc;
            }
            // Check if this location matches the current page
            if ( is_array($loc['location_pages']) && in_array($post_id, $loc['location_pages']) ) {
                $current_page_location = $loc;
            }
        }
    }

    // Default to Primary on Front Page even if not mapped
    if ( is_front_page() && $primary_location ) {
        $current_page_location = $primary_location;
    }

    // Set Global Clinic ID based on Primary Location Fragment
    $base_url = get_home_url();
    $primary_fragment = $primary_location['location_fragment'] ?? '#main-clinic';
    $clinic_id = $base_url . $primary_fragment;

    // --- FORK: TREATMENT POST TYPE ---
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

        // --- FORK A: PROCEDURE SCHEMA ---
        if ( $is_procedure ) {
            $body_locations = get_field('procedure_body_location', $post_id);
            $procedure_entity = [
                "@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", 
                "@id" => get_permalink($post_id) . "#procedure", 
                "name" => $clinical_name ?: get_the_title($post_id),
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
                "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id]
            ];
            
            if ( is_array($body_locations) ) $procedure_entity["bodyLocation"] = $body_locations;

            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) { 
                $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $alts))); 
            }

            $treatment_schema["mainEntity"][] = $procedure_entity;

            $condition_repeater = get_field('medical_conditions_repeater', $post_id);
            if (is_array($condition_repeater)) { 
                foreach ($condition_repeater as $row) { 
                    $c_raw = $row['internal_condition'] ?? null;
                    if ( !empty($c_raw) ) {
                        $c_posts = is_array($c_raw) ? $c_raw : [$c_raw];
                        foreach ($c_posts as $c_item) {
                            $c_id = (is_object($c_item)) ? $c_item->ID : (is_numeric($c_item) ? $c_item : 0);
                            if ($c_id > 0) { 
                                $c_node = ["@type" => "MedicalCondition", "@id" => get_permalink($c_id) . "#condition", "name" => get_the_title($c_id), "url" => get_permalink($c_id), "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]];
                                $c_desc = get_field('condition_description', $c_id) ?: get_the_excerpt($c_id);
                                if (!empty($c_desc)) $c_node["description"] = trim($c_desc);
                                $treatment_schema["mainEntity"][] = $c_node; 
                            }
                        }
                    }
                    $manual_listing = $row['medical_conditions_listing'] ?? null;
                    if ( is_array($manual_listing) ) {
                        foreach ( $manual_listing as $m_row ) {
                            if ( !empty($m_row['name']) ) {
                                $m_node = ["@type" => "MedicalCondition", "name" => $m_row['name'], "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]];
                                if (!empty($m_row['description'])) $m_node["description"] = trim($m_row['description']);
                                $treatment_schema["mainEntity"][] = $m_node;
                            }
                        }
                    }
                } 
            }
        }
        // --- FORK B: CONDITION SCHEMA ---
        elseif ( $is_condition ) {
            $raw_anatomy = get_field('condition_associated_anatomy', $post_id);
            $anatomy_list = [];
            if ( !empty($raw_anatomy) ) {
                $lines = array_filter(array_map('trim', explode("\n", $raw_anatomy)));
                foreach ($lines as $line) { $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => $line]; }
            } else { $anatomy_list[] = ["@type" => "AnatomicalStructure", "name"  => "Skin"]; }

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

            if ( is_array($linked_providers) && !empty($linked_providers) ) {
                $rev_id = $linked_providers[0]; 
                $condition_node["reviewedBy"] = ["@type" => get_field('provider_type', $rev_id) ?: 'Person', "name"  => get_the_title($rev_id), "url" => get_permalink($rev_id)];
            }
            
            $symptoms = get_field('condition_symptoms', $post_id);
            if (is_array($symptoms)) { foreach ($symptoms as $s) { if (!empty($s['symptom_name'])) $condition_node["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s['symptom_name']]; } }

            $treatments_list = get_field('possible_treatments_repeater', $post_id);
            if (is_array($treatments_list)) {
                foreach ($treatments_list as $t_row) {
                    $t_ids = $t_row['internal_treatment'] ?? null;
                    if (is_array($t_ids)) {
                        foreach ($t_ids as $t_item) {
                            $t_id = (is_object($t_item)) ? $t_item->ID : (is_numeric($t_item) ? $t_item : 0);
                            if ($t_id > 0) $condition_node["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => get_the_title($t_id), "description" => get_field("procedure_description", $t_id) ?: null, "url" => get_permalink($t_id)];
                        }
                    }
                }
            }
            
            $sources = get_field('source_of_truth', $post_id);
            if (is_array($sources)) { foreach ($sources as $s) { if (!empty($s['same_as'])) $condition_node["sameAs"][] = $s['same_as']; } }
            
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) $condition_node["alternateName"] = array_filter(array_map('trim', explode("\n", $alts)));

            if (empty($condition_node["signOrSymptom"])) unset($condition_node["signOrSymptom"]);
            if (empty($condition_node["possibleTreatment"])) unset($condition_node["possibleTreatment"]);
            $treatment_schema["mainEntity"][] = $condition_node;
        }

        // --- SHARED PROVIDERS BLOCK (Including Clinic @id) ---
        $treatment_schema["provider"][] = ["@type" => "MedicalClinic", "@id" => $clinic_id];

        if ( is_array($linked_providers) ) {
            foreach ($linked_providers as $p_id) { 
                $p_t = get_field('provider_type', $p_id) ?: 'Person'; $npi = get_field('provider_npi', $p_id); 
                $p_o = ["@type" => $p_t, "name" => get_the_title($p_id), "url" => get_permalink($p_id)]; 
                if (!empty($npi)) { if ($p_t === 'Physician') $p_o["usNPI"] = $npi; else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; } 
                $treatment_schema["provider"][] = $p_o; 
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- PROTECTED PROVIDER PAGES (Team) ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person'; $npi = get_field('provider_npi', $post_id);
        $provider_entity = ["@type" => ["Person", $p_type], "@id" => get_permalink($post_id) . "#provider", "name" => get_the_title($post_id), "jobTitle" => get_field('exact_job_title', $post_id), "url" => get_permalink($post_id), "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], "worksFor" => ["@type" => "MedicalClinic", "@id" => $clinic_id]];
        
        $credentials = get_field('educational_occupational_credential', $post_id);
        if ( is_array($credentials) ) {
            foreach ( $credentials as $cred ) {
                $c_obj = ["@type" => "EducationalOccupationalCredential", "name" => $cred['name']];
                $provider_entity["hasCredential"][] = $c_obj;
            }
        }
        $edu = get_field('education_history', $post_id); 
        if (is_array($edu)) { foreach ($edu as $e) { if ($e['organization_name']) $provider_entity["alumniOf"][] = ["@type" => "EducationalOrganization", "name" => $e['organization_name']]; } }
        
        if (!empty($npi)) { if ($p_type === 'Physician') $provider_entity["usNPI"] = $npi; else $provider_entity["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }
        echo "\n<script type='application/ld+json'>" . json_encode(["@context" => "https://schema.org", "@graph" => [["@type" => "MedicalWebPage", "name" => get_the_title($post_id)], $provider_entity]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

// --- SATELLITE / GLOBAL CLINIC INJECTION ---
    if ( $current_page_location ) {
        $loc_id = get_home_url() . ($current_page_location['location_fragment'] ?: '#clinic');
        
        // 1. Determine Entity Type
        // Primary = MedicalOrganization (Authority) | Satellite = MedicalClinic (Local Branch)
        $is_primary_row = !empty($current_page_location['is_primary']);
        $entity_type = $is_primary_row ? "MedicalOrganization" : "MedicalClinic";

        // 2. Fetch Global Practice Data
        $global_logo = get_field('global_clinic_logo', 'option');
        $global_price = get_field('global_clinic_pricerange', 'option') ?: '$$';
        $global_sameas_raw = get_field('global_clinic_sameas', 'option');
        $global_sameas = !empty($global_sameas_raw) ? array_filter(array_map('trim', explode("\n", $global_sameas_raw))) : [];
        $insurance_raw = get_field('global_clinic_insurance', 'option');
        $insurance_list = !empty($insurance_raw) ? array_filter(array_map('trim', explode("\n", $insurance_raw))) : [];

        $location_schema = [
            "@context" => "https://schema.org",
            "@type" => $entity_type, // Dynamically toggles based on primary status
            "@id" => $loc_id,
            "name" => $current_page_location['location_name'],
            "telephone" => $current_page_location['phone'],
            "medicalSpecialty" => "Dermatology",
            "priceRange" => $global_price,
            "isAcceptingNewPatients" => (bool)($current_page_location['is_accepting_new_patients'] ?? true),
            "address" => [
                "@type" => "PostalAddress", 
                "streetAddress" => $current_page_location['address']['street'] ?? '', 
                "addressLocality" => $current_page_location['address']['city'] ?? '', 
                "addressRegion" => $current_page_location['address']['state'] ?? '', 
                "postalCode" => $current_page_location['address']['zip'] ?? '', 
                "addressCountry" => "US"
            ],
            "geo" => [
                "@type" => "GeoCoordinates", 
                "latitude" => $current_page_location['geo']['lat'] ?? '', 
                "longitude" => $current_page_location['geo']['long'] ?? ''
            ],
            "openingHoursSpecification" => []
        ];

        // 3. Optimized Standard Hours (Collapsed Days Logic)
        if ( !empty($current_page_location['opening_hours']) ) {
            foreach ($current_page_location['opening_hours'] as $h) {
                if ( !empty($h['days']) ) {
                    $spec = [
                        "@type" => "OpeningHoursSpecification", 
                        "dayOfWeek" => $h['days'] // Collapses Mon-Fri into a flat array
                    ];
                    if ( empty($h['opens']) || empty($h['closes']) ) {
                        $spec["opens"] = "00:00";
                        $spec["closes"] = "00:00";
                    } else {
                        $spec["opens"] = date("H:i", strtotime($h['opens']));
                        $spec["closes"] = date("H:i", strtotime($h['closes']));
                    }
                    $location_schema["openingHoursSpecification"][] = $spec;
                }
            }
        }

        // 4. Public Holidays Logic (Specific Dates / Closed Status)
        $holidays = $current_page_location['public_holidays'] ?? null;
        if ( is_array($holidays) ) {
            foreach ( $holidays as $hol ) {
                if ( !empty($hol['date']) ) {
                    $h_spec = ["@type" => "OpeningHoursSpecification", "validFrom" => $hol['date'], "validThrough" => $hol['date']];
                    if ( empty($hol['opens']) || empty($hol['closes']) ) {
                        $h_spec["opens"] = "00:00";
                        $h_spec["closes"] = "00:00";
                    } else {
                        $h_spec["opens"] = date("H:i", strtotime($hol['opens']));
                        $h_spec["closes"] = date("H:i", strtotime($hol['closes']));
                    }
                    $location_schema["openingHoursSpecification"][] = $h_spec;
                }
            }
        }

        // 5. Global Injections
        if ( !empty($global_logo) ) $location_schema["logo"] = $global_logo;
        if ( !empty($global_sameas) ) $location_schema["sameAs"] = $global_sameas;
        if ( !empty($insurance_list) ) $location_schema["paymentAccepted"] = $insurance_list;
        
        // 6. Parent Organization Connection
        if ( !$is_primary_row && $primary_location ) {
            $location_schema["parentOrganization"] = [
                "@type" => "MedicalOrganization", 
                "@id" => $clinic_id, 
                "name" => $primary_location['location_name']
            ];
        }

        echo "\n<script type='application/ld+json'>" . json_encode($location_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}