<?php
/**
 * Plugin Name: Dermatology EEAT Schema
 * Description: Version 7.0 - Expanded Physician & Person schema
 * Version: 7.0
 * Author: DIGITAL ATLAS + Gemini AI Collaboration
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
    
    // Global Options Data
    $clinic_name     = get_field('clinic_name', 'option');
    $clinic_street   = get_field('clinic_street', 'option');
    $clinic_city     = get_field('clinic_city', 'option');
    $clinic_state    = get_field('clinic_state', 'option');
    $clinic_zip      = get_field('clinic_zip', 'option');
    $clinic_phone    = get_field('clinic_phone', 'option');
    $clinic_lat      = get_field('clinic_lat', 'option');
    $clinic_lng      = get_field('clinic_long', 'option');
    $custom_fragment = get_field('clinic_fragment_id', 'option');
    $display_pages   = get_field('schema_display_pages', 'option');
    $clinic_id       = get_home_url() . "#" . ($custom_fragment ?: 'main-clinic');

// --- A. ADVANCED PROVIDER PAGES (Team Post Type) ---
    if ( is_singular('team') ) {
        $p_type      = get_field('provider_type', $post_id) ?: 'Person';
        $npi         = get_field('provider_npi', $post_id);
        $job_title   = get_field('exact_job_title', $post_id); 
        $prefixes    = get_field('honorific_prefix', $post_id);
        $suffixes    = get_field('honorific_suffix', $post_id);
        $specialties = get_field('medical_specialties', $post_id);
        $edu_history = get_field('education_history', $post_id);
        $affiliations= get_field('affiliations', $post_id);
        $p_sameas    = get_field('provider_profiles_sameas', $post_id);
        $knows_about = get_field('knows_about', $post_id);
        
        // Credential Repeater
        $credentials_raw = get_field('educational_occupational_credential', $post_id);

        $provider_entity = [
            "@type" => ["Person", $p_type],
            "@id" => get_permalink() . "#provider",
            "name" => get_the_title(),
            "jobTitle" => $job_title,
            "url" => get_permalink(),
            "medicalSpecialty" => !empty($specialties) ? $specialties : ["Dermatology"],
            "worksFor" => ["@type" => "MedicalClinic", "@id" => $clinic_id],
            "sameAs" => []
        ];

        // Explode knowsAbout
        if ( !empty($knows_about) ) {
            $provider_entity["knowsAbout"] = array_filter(array_map('trim', explode("\n", $knows_about)));
        }

        // Map Credentials with Select Field Support
        if ( is_array($credentials_raw) ) {
            $provider_entity["hasCredential"] = [];
            foreach ( $credentials_raw as $cred ) {
                $credential_obj = [
                    "@type" => "EducationalOccupationalCredential",
                    "name" => $cred['name'],
                    "credentialCategory" => $cred['credential_category'] // Now a Select Field
                ];

                // Child Repeater: recognizedBy
                $recognized_by_raw = $cred['recognized_by']; 
                if ( is_array($recognized_by_raw) ) {
                    foreach ( $recognized_by_raw as $org ) {
                        $credential_obj["recognizedBy"] = [
                            // "type" is now a Select Field (e.g., EducationalOrganization or Organization)
                            "@type" => !empty($org['type']) ? $org['type'] : "EducationalOrganization",
                            "name" => $org['name']
                        ];
                    }
                }
                $provider_entity["hasCredential"][] = $credential_obj;
            }
        }

        // Map Honorifics
        if ( !empty($prefixes) ) { $provider_entity["honorificPrefix"] = is_array($prefixes) ? implode(', ', $prefixes) : $prefixes; }
        if ( !empty($suffixes) ) { $provider_entity["honorificSuffix"] = is_array($suffixes) ? implode(', ', $suffixes) : $suffixes; }

        // Map alumniOf, Affiliations, and sameAs
        if ( is_array($edu_history) ) { foreach ( $edu_history as $edu ) { if ( !empty($edu['organization_name']) ) { $provider_entity["alumniOf"][] = ["@type" => "EducationalOrganization", "name" => $edu['organization_name']]; } } }
        if ( is_array($affiliations) ) { foreach ( $affiliations as $aff ) { if ( !empty($aff['name']) ) { $provider_entity["affiliation"][] = ["@type" => "Hospital", "name" => $aff['name']]; } } }
        if ( is_array($p_sameas) ) { foreach ( $p_sameas as $s_row ) { if ( !empty($s_row['url']) ) { $provider_entity["sameAs"][] = $s_row['url']; } } }

        // NPI Logic
        if ( !empty($npi) ) {
            if ( $p_type === 'Physician' ) { $provider_entity["usNPI"] = $npi; }
            else { $provider_entity["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }
        }

        $provider_schema = [
            "@context" => "https://schema.org",
            "@graph" => [
                [
                    "@type" => "MedicalWebPage",
                    "@id" => get_permalink() . "#webpage",
                    "url" => get_permalink(),
                    "name" => get_the_title(),
                    "lastReviewed" => get_the_modified_date('c')
                ],
                $provider_entity
            ]
        ];
        
        echo "\n<script type='application/ld+json'>" . json_encode($provider_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- B. TREATMENT & CONDITION PAGES ---
    if ( is_singular( array( 'treatment', 'service' ) ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_aesthetic = in_array( 'aesthetics', $categories );

        $clinical_name = get_field('clinical_name', $post_id);
        $description   = get_field('procedure_description', $post_id); 
        $linked_providers = get_field('treatment_providers', $post_id);
        
        $treatment_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalWebPage",
            "name" => get_the_title() . " at " . ($clinic_name ?: get_bloginfo('name')),
            "description" => $description ?: get_the_excerpt(),
            "lastReviewed" => get_the_modified_date('c'),
            "mainEntity" => [] 
        ];

        if ( $is_aesthetic ) {
            $proc_type = get_field('medical_procedure_type', $post_id);
            $prep = get_field('procedure_preparation', $post_id);
            $followup = get_field('procedure_followup', $post_id);
            $body_loc = get_field('procedure_body_location', $post_id);
            $alt_names_raw = get_field('alternate_names', $post_id);
            $primary_img = get_field('primary_image', $post_id);

            $procedure_entity = [
                "@type" => !empty($proc_type) ? $proc_type : "MedicalProcedure",
                "@id"   => get_permalink($post_id) . "#procedure",
                "name"  => $clinical_name ?: get_the_title(),
                "preparation" => $prep,
                "followup"    => $followup,
                "bodyLocation" => is_array($body_loc) ? implode(', ', $body_loc) : $body_loc,
                "sameAs" => []
            ];

            if ( $primary_img ) {
                $procedure_entity["image"] = ["@type" => "ImageObject", "url" => is_array($primary_img) ? $primary_img['url'] : wp_get_attachment_url($primary_img)];
            }

            $p_sources = get_field('source_of_truth', $post_id);
            if ( is_array($p_sources) ) { foreach ( $p_sources as $s_row ) { if ( !empty($s_row['same_as']) ) $procedure_entity["sameAs"][] = $s_row['same_as']; } }
            if ( !empty($alt_names_raw) ) { $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $alt_names_raw))); }

            $treatment_schema["mainEntity"][] = $procedure_entity;

            $condition_repeater = get_field('medical_conditions_repeater', $post_id);
            if ( $condition_repeater ) {
                foreach ( $condition_repeater as $row ) {
                    $c_raw = $row['internal_treatment'];
                    $target_id = (is_object($c_raw) && isset($c_raw->ID)) ? $c_raw->ID : (is_numeric($c_raw) ? $c_raw : 0);
                    if ( $target_id > 0 ) {
                        $condition_obj = ["@type" => "MedicalCondition", "@id" => get_permalink($target_id) . "#condition", "name" => get_the_title($target_id), "url" => get_permalink($target_id), "signOrSymptom" => [], "sameAs" => []];
                        $c_alt_names = get_field('alternate_names', $target_id);
                        if ( !empty($c_alt_names) ) $condition_obj["alternateName"] = array_filter(array_map('trim', explode("\n", $c_alt_names)));
                        $c_img = get_field('primary_image', $target_id);
                        if ($c_img) $condition_obj["image"] = ["@type" => "ImageObject", "url" => is_array($c_img) ? $c_img['url'] : wp_get_attachment_url($c_img)];
                        $c_sources = get_field('source_of_truth', $target_id);
                        if (is_array($c_sources)) { foreach ($c_sources as $s_row) { if (!empty($s_row['same_as'])) $condition_obj["sameAs"][] = $s_row['same_as']; } }
                        $ext_symptoms = get_field('condition_symptoms', $target_id); 
                        if (is_array($ext_symptoms)) { foreach ($ext_symptoms as $s_row) { if (!empty($s_row['symptom_name'])) $condition_obj["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s_row['symptom_name']]; } }
                        $treatment_schema["mainEntity"][] = $condition_obj;
                    }
                }
            }
        } else {
            $condition_entity = ["@type" => "MedicalCondition", "@id" => get_permalink($post_id) . "#condition", "name" => $clinical_name ?: get_the_title(), "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"], "signOrSymptom" => [], "sameAs" => []];
            $c_alt = get_field('alternate_names', $post_id);
            if (!empty($c_alt)) $condition_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $c_alt)));
            $symptoms = get_field('condition_symptoms', $post_id);
            if (is_array($symptoms)) { foreach ($symptoms as $row) { $condition_entity["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $row['symptom_name']]; } }
            $treatment_schema["mainEntity"][] = $condition_entity;
        }

        if ( $linked_providers ) {
            $reviewer_id = $linked_providers[0];
            $rev_type    = get_field('provider_type', $reviewer_id) ?: 'Person';
            $rev_npi     = get_field('provider_npi', $reviewer_id);
            $reviewer_obj = ["@type" => $rev_type, "name" => get_the_title($reviewer_id)];
            if (!empty($rev_npi)) { if ($rev_type === 'Physician') $reviewer_obj["usNPI"] = $rev_npi; else $reviewer_obj["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $rev_npi]; }
            $treatment_schema["reviewedBy"] = $reviewer_obj;
            foreach ( $linked_providers as $p_id ) {
                $p_type = get_field('provider_type', $p_id) ?: 'Person';
                $npi    = get_field('provider_npi', $p_id);
                $provider_obj = ["@type" => $p_type, "name" => get_the_title($p_id), "url" => get_permalink($p_id)];
                if (!empty($npi)) { if ($p_type === 'Physician') $provider_obj["usNPI"] = $npi; else $provider_obj["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }
                $treatment_schema["provider"][] = $provider_obj;
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- C. BLOG POSTS ---
    if ( is_singular('post') ) {
        $blog_schema = [
            "@context" => "https://schema.org",
            "@type" => "BlogPosting",
            "headline" => get_the_title(),
            "datePublished" => get_the_date('c'),
            "dateModified" => get_the_modified_date('c'),
            "author" => ["@type" => "Organization", "name" => $clinic_name ?: get_bloginfo('name')],
            "publisher" => ["@type" => "MedicalClinic", "@id" => $clinic_id]
        ];
        echo "\n<script type='application/ld+json'>" . json_encode($blog_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- D. GLOBAL CLINIC SCHEMA ---
    if ( is_front_page() || ( is_page() && is_array($display_pages) && in_array(get_the_ID(), $display_pages) ) ) {
        $logo_field = get_field('logo', 'option');
        $logo_url = is_array($logo_field) ? $logo_field['url'] : (is_numeric($logo_field) ? wp_get_attachment_url($logo_field) : $logo_field);

        $clinic_schema = [
            "@context" => "https://schema.org",
            "@type" => "MedicalClinic",
            "@id" => $clinic_id,
            "name" => $clinic_name ?: get_bloginfo('name'),
            "logo" => ["@type" => "ImageObject", "url" => $logo_url ?: get_site_icon_url()],
            "address" => ["@type" => "PostalAddress", "streetAddress" => $clinic_street, "addressLocality" => $clinic_city, "addressRegion" => $clinic_state, "postalCode" => $clinic_zip, "addressCountry" => "US"],
            "geo" => ["@type" => "GeoCoordinates", "latitude" => $clinic_lat, "longitude" => $clinic_lng],
            "telephone" => $clinic_phone,
            "url" => get_home_url(),
            "openingHoursSpecification" => [] // Initialized for repeater
        ];

        // NEW: Hours Repeater Logic
        $hours_repeater = get_field('hours_repeater', 'option');
        if ( is_array($hours_repeater) ) {
            foreach ( $hours_repeater as $h_row ) {
                $days = $h_row['days']; // Expects Array of Day names (e.g. Monday)
                $opens = $h_row['opens']; // Expects 24h format (e.g. 09:00)
                $closes = $h_row['closes']; // Expects 24h format (e.g. 17:00)

                if ( !empty($days) && !empty($opens) && !empty($closes) ) {
                    $clinic_schema["openingHoursSpecification"][] = [
                        "@type" => "OpeningHoursSpecification",
                        "dayOfWeek" => $days,
                        "opens" => $opens,
                        "closes" => $closes
                    ];
                }
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($clinic_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}