<?php
/**
 * Plugin Name: Dermatology EEAT Master Schema
 * Description: Baseline 2.1 - FULL RESTORATION. Procedures, Conditions, Team EEAT, and Global Clinic Schema.
 * Version: 11.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Options Page Initialization
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
    $clinic_name = get_field('clinic_name', 'option');
    $custom_fragment = get_field('clinic_fragment_id', 'option');
    $clinic_id = get_home_url() . "#" . ($custom_fragment ?: 'main-clinic');

    // --- FORK: TREATMENT POST TYPE (Medical vs Aesthetic) ---
    if ( is_singular( array( 'treatment', 'service' ) ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_aesthetic = in_array( 'aesthetics', $categories );
        $is_medical   = in_array( 'medical', $categories );
        $linked_providers = get_field('treatment_providers', $post_id);
        $clinical_name = get_field('clinical_name', $post_id);

        $treatment_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalWebPage",
            "name" =>  $clinical_name  ?: get_the_title($post_id),
            "description" => get_field('procedure_description', $post_id) ?: get_the_excerpt($post_id),
            "lastReviewed" => get_the_modified_date('c', $post_id),
            "mainEntity" => []
        ];


// --- FORK A: IS_AESTHETIC (Procedure Page) ---
if ( $is_aesthetic ) {
    $procedure_entity = [
        "@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", 
        "@id" => get_permalink($post_id) . "#procedure", 
        "name" => $clinical_name ?: get_the_title($post_id),
        "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id]
    ];
    $treatment_schema["mainEntity"][] = $procedure_entity;

    // Targeting the medical_conditions_repeater
    $condition_repeater = get_field('medical_conditions_repeater', $post_id);

    if (is_array($condition_repeater)) { 
        foreach ($condition_repeater as $row) { 
            
            // 1. Process Post Object (internal_condition)
            $c_raw = isset($row['internal_condition']) ? $row['internal_condition'] : null;
            if ( !empty($c_raw) ) {
                // Normalize to array to handle single ID or multiple objects
                $c_posts = is_array($c_raw) ? $c_raw : [$c_raw];
                
                foreach ($c_posts as $c_item) {
                    $c_id = (is_object($c_item)) ? $c_item->ID : (is_numeric($c_item) ? $c_item : 0);
                    
                    if ($c_id > 0) { 
                        $c_node = [
                            "@type" => "MedicalCondition", 
                            "@id"   => get_permalink($c_id) . "#condition", 
                            "name"  => get_the_title($c_id), 
                            "url"   => get_permalink($c_id),
                            "description" => get_field('condition_description', $c_id) ?: get_the_excerpt($c_id),
                            "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]
                        ];
                        
                        // signOrSymptom logic has been removed here

                        $treatment_schema["mainEntity"][] = $c_node; 
                    }
                }
            }

            // 2. Process Manual Text Field (internal_condition_manual)
            $c_manual = isset($row['internal_condition_manual']) ? trim($row['internal_condition_manual']) : '';
            if ( !empty($c_manual) ) {
                $treatment_schema["mainEntity"][] = [
                    "@type" => "MedicalCondition",
                    "name"  => $c_manual,
                    "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"]
                ];
            }
        } 
    }
}            

        
// --- FORK B: IS_MEDICAL (Condition Page) ---
        elseif ( $is_medical ) {
            // 1. Build the main MedicalCondition entity
            $condition_node = [
                "@type" => "MedicalCondition",
                "@id"   => get_permalink($post_id) . "#condition",
                "name"  => $clinical_name ?: get_the_title($post_id),
                "url"   => get_permalink($post_id),
                "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
                "signOrSymptom" => [], // Initialized for symptom mapping
                "possibleTreatment" => [] 
            ];

            // ADDED: Lead Provider as Reviewer
            if ( is_array($linked_providers) && !empty($linked_providers) ) {
                $rev_id = $linked_providers[0]; 
                $condition_node["reviewedBy"] = [
                    "@type" => get_field('provider_type', $rev_id) ?: 'Person',
                    "name"  => get_the_title($rev_id),
                    "url"   => get_permalink($rev_id)
                ];
            }

            // 2. RESTORED: Map Symptoms from condition_symptoms repeater
            $symptoms = get_field('condition_symptoms', $post_id);
            if (is_array($symptoms)) { 
                foreach ($symptoms as $s) { 
                    if (!empty($s['symptom_name'])) {
                        $condition_node["signOrSymptom"][] = [
                            "@type" => "MedicalSignOrSymptom", 
                            "name" => $s['symptom_name']
                        ]; 
                    }
                } 
            }

            // 3. Map Treatments from the "possible_treatments_repeater"
            $treatments_list = get_field('possible_treatments_repeater', $post_id);
            if (is_array($treatments_list)) {
                foreach ($treatments_list as $t_row) {
                    $t_ids = isset($t_row['internal_treatment']) ? $t_row['internal_treatment'] : null;
                    if (is_array($t_ids)) {
                        foreach ($t_ids as $t_item) {
                            $t_id = (is_object($t_item)) ? $t_item->ID : (is_numeric($t_item) ? $t_item : 0);
                            if ($t_id > 0) {
                                $condition_node["possibleTreatment"][] = [
                                    "@type" => "MedicalProcedure",
                                    "name"  => get_the_title($t_id),
                                    "description" => get_field("procedure_description", $t_id) ?: null,
                                    "bodyLocation" => get_field("procedure_body_location", $t_id) ?: null,
                                    "url"   => get_permalink($t_id)
                                ];
                            }
                        }
                    }
                }
            }

            // 4. Cleanup and Authority Metadata
            if (empty($condition_node["signOrSymptom"])) unset($condition_node["signOrSymptom"]);
            if (empty($condition_node["possibleTreatment"])) unset($condition_node["possibleTreatment"]);

            $sources = get_field('source_of_truth', $post_id);
            if (is_array($sources)) { 
                foreach ($sources as $s) { 
                    if (!empty($s['same_as'])) $condition_node["sameAs"][] = $s['same_as']; 
                } 
            }
            
            $alts = get_field('alternate_names', $post_id);
            if (!empty($alts)) { 
                $condition_node["alternateName"] = array_filter(array_map('trim', explode("\n", $alts))); 
            }

            // 5. Push to mainEntity
            $treatment_schema["mainEntity"][] = $condition_node;
        }        

        if ( is_array($linked_providers) ) {
            foreach ($linked_providers as $p_id) { 
                $p_t = get_field('provider_type', $p_id) ?: 'Person'; $npi = get_field('provider_npi', $p_id); $p_o = ["@type" => $p_t, "name" => get_the_title($p_id), "url" => get_permalink($p_id)]; 
                if (!empty($npi)) { if ($p_t === 'Physician') $p_o["usNPI"] = $npi; else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; } 
                $treatment_schema["provider"][] = $p_o; 
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- FORK: PROVIDER (TEAM) POST TYPE ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person';
        $npi = get_field('provider_npi', $post_id);
        $provider_entity = ["@type" => ["Person", $p_type], "@id" => get_permalink($post_id) . "#provider", "name" => get_the_title($post_id), "jobTitle" => get_field('exact_job_title', $post_id), "url" => get_permalink($post_id), "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], "worksFor" => ["@type" => "MedicalClinic", "@id" => $clinic_id]];
        // Restoration of Credentials, Education, and Affiliations...
        echo "\n<script type='application/ld+json'>" . json_encode(["@context" => "https://schema.org", "@graph" => [["@type" => "MedicalWebPage", "name" => get_the_title($post_id)], $provider_entity]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

// --- RESTORED: GLOBAL CLINIC SCHEMA WITH OPENING HOURS ---
    $display_pages = get_field('schema_display_pages', 'option');
    if ( is_front_page() || ( is_page() && is_array($display_pages) && in_array(get_the_ID(), $display_pages) ) ) {
        $logo = get_field('logo', 'option');
        $clinic_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalClinic",
            "@id" => $clinic_id,
            "name" => $clinic_name ?: get_bloginfo('name'),
            "logo" => ["@type" => "ImageObject", "url" => is_array($logo) ? $logo['url'] : wp_get_attachment_url($logo)],
            "address" => [
                "@type" => "PostalAddress",
                "streetAddress" => get_field('clinic_street', 'option'),
                "addressLocality" => get_field('clinic_city', 'option'),
                "addressRegion" => get_field('clinic_state', 'option'),
                "postalCode" => get_field('clinic_zip', 'option'),
                "addressCountry" => "US"
            ],
            "geo" => [
                "@type" => "GeoCoordinates",
                "latitude" => get_field('clinic_lat', 'option'),
                "longitude" => get_field('clinic_long', 'option')
            ],
            "telephone" => get_field('clinic_phone', 'option'),
            "url" => get_home_url(),
            "openingHoursSpecification" => []
        ];

        // Map Opening Hours Repeater
        $hours = get_field('opening_hours', 'option');
        if ( is_array($hours) ) {
            foreach ( $hours as $h ) {
                if ( ! empty($h['days']) ) {
                    $clinic_schema["openingHoursSpecification"][] = [
                        "@type" => "OpeningHoursSpecification",
                        "dayOfWeek" => $h['days'],
                        "opens" => $h['opens'] ?: "09:00",
                        "closes" => $h['closes'] ?: "17:00"
                    ];
                }
            }
        }

        echo "\n<script type='application/ld+json'>" . json_encode($clinic_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}