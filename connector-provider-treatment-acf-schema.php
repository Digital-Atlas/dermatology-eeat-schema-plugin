<?php
/**
 * Plugin Name: Dermatology EEAT Master Schema
 * Description: Version 8.4 - REGRESSION PROTECTION. Strict mapping for Credentials, NPI, and Treatment Links.
 * Version: 8.4
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

// 2. Health Dashboard Widget
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget('gd_schema_health_widget', 'Schema E-E-A-T Health Check', 'gd_render_schema_health_widget');
});

function gd_render_schema_health_widget() {
    $providers = get_posts(['post_type' => 'team', 'numberposts' => -1]);
    $missing_npi = [];
    foreach ($providers as $p) { if ( empty(get_field('provider_npi', $p->ID)) ) { $missing_npi[] = get_the_title($p->ID); } }
    $conditions = get_posts(['post_type' => 'treatment', 'numberposts' => -1]);
    $missing_sameas = [];
    foreach ($conditions as $c) { 
        $sources = get_field('source_of_truth', $c->ID); 
        if ( empty($sources) || (is_array($sources) && empty($sources[0]['same_as'])) ) { $missing_sameas[] = get_the_title($c->ID); } 
    }
    echo '<div style="padding:10px;">';
    if ( empty($missing_npi) && empty($missing_sameas) ) { echo '<p style="color:green;"><strong>✔ All E-E-A-T signals are looking strong!</strong></p>'; } 
    else {
        if ( !empty($missing_npi) ) { echo '<p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>Missing NPI:</strong><br><small>' . implode(', ', $missing_npi) . '</small></p>'; }
        if ( !empty($missing_sameas) ) { echo '<p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>Missing SameAs Links:</strong><br><small>' . implode(', ', $missing_sameas) . '</small></p>'; }
    }
    echo '<hr><p><a href="' . admin_url('admin.php?page=clinic-schema-settings') . '" class="button">Update Clinic Settings</a></p></div>';
}

// 3. Schema Injection Logic
add_action( 'wp_head', 'gd_inject_eeat_schema' );

function gd_inject_eeat_schema() {
    $post_id = get_the_ID();
    $clinic_name = get_field('clinic_name', 'option');
    $clinic_street = get_field('clinic_street', 'option');
    $clinic_city = get_field('clinic_city', 'option');
    $clinic_state = get_field('clinic_state', 'option');
    $clinic_zip = get_field('clinic_zip', 'option');
    $clinic_phone = get_field('clinic_phone', 'option');
    $clinic_lat = get_field('clinic_lat', 'option');
    $clinic_lng = get_field('clinic_long', 'option');
    $custom_fragment = get_field('clinic_fragment_id', 'option');
    $display_pages = get_field('schema_display_pages', 'option');
    $clinic_id = get_home_url() . "#" . ($custom_fragment ?: 'main-clinic');

    // PROTECTED HELPER: MAP CONDITION
    $map_condition = function($id) use ($clinic_id) {
        if (!$id) return null; 
        $c_obj = [
            "@type" => "MedicalCondition",
            "@id" => get_permalink($id) . "#condition",
            "name" => get_the_title($id),
            "url" => get_permalink($id),
            "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id],
            "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
            "signOrSymptom" => [],
            "possibleTreatment" => [],
            "sameAs" => [],
            "alternateName" => []
        ];

        // Map Symptoms
        $symptoms = get_field('condition_symptoms', $id);
        if (is_array($symptoms)) { foreach ($symptoms as $s) { if (!empty($s['symptom_name'])) $c_obj["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s['symptom_name']]; } }
        
        // PROTECTED: possible_treatments_repeater
        $treatments = get_field('possible_treatments_repeater', $id);
        if (is_array($treatments)) { 
            foreach ($treatments as $row) { 
                $t_raw = isset($row['internal_treatment']) ? $row['internal_treatment'] : null; 
                $t_id = (is_object($t_raw) && isset($t_raw->ID)) ? $t_raw->ID : (is_array($t_raw) && isset($t_raw['ID']) ? $t_raw['ID'] : (is_numeric($t_raw) ? (int)$t_raw : 0));
                if ($t_id > 0) { $c_obj["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => get_the_title($t_id), "url" => get_permalink($t_id)]; }
            } 
        }

        $sources = get_field('source_of_truth', $id);
        if (is_array($sources)) { foreach ($sources as $s) { if (!empty($s['same_as'])) $c_obj["sameAs"][] = $s['same_as']; } }
        $alts = get_field('alternate_names', $id);
        if (!empty($alts)) { $c_obj["alternateName"] = array_filter(array_map('trim', explode("\n", $alts))); }
        return $c_obj;
    };

    // --- A. PROTECTED PROVIDER PAGES (Team) ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person';
        $npi = get_field('provider_npi', $post_id);
        $provider_entity = [
            "@type" => ["Person", $p_type], 
            "@id" => get_permalink($post_id) . "#provider", 
            "name" => get_the_title($post_id), 
            "jobTitle" => get_field('exact_job_title', $post_id), 
            "url" => get_permalink($post_id), 
            "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], 
            "worksFor" => ["@type" => "MedicalClinic", "@id" => $clinic_id]
        ];

        // MANDATORY: EducationalOccupationalCredential Restoration
        $credentials = get_field('educational_occupational_credential', $post_id);
        if ( is_array($credentials) ) {
            $provider_entity["hasCredential"] = [];
            foreach ( $credentials as $cred ) {
                $credential_obj = ["@type" => "EducationalOccupationalCredential", "name" => $cred['name'], "credentialCategory" => $cred['credential_category']];
                if ( is_array($cred['recognized_by']) ) {
                    foreach ( $cred['recognized_by'] as $org ) {
                        $credential_obj["recognizedBy"] = ["@type" => !empty($org['type']) ? $org['type'] : "EducationalOrganization", "name" => $org['name']];
                    }
                }
                $provider_entity["hasCredential"][] = $credential_obj;
            }
        }

        // Additional E-E-A-T Fields
        $knows = get_field('knows_about', $post_id); if ($knows) $provider_entity["knowsAbout"] = array_filter(array_map('trim', explode("\n", $knows)));
        $prefixes = get_field('honorific_prefix', $post_id); if ($prefixes) $provider_entity["honorificPrefix"] = is_array($prefixes) ? implode(', ', $prefixes) : $prefixes;
        $suffixes = get_field('honorific_suffix', $post_id); if ($suffixes) $provider_entity["honorificSuffix"] = is_array($suffixes) ? implode(', ', $suffixes) : $suffixes;
        $edu = get_field('education_history', $post_id); if (is_array($edu)) { foreach ($edu as $e) { if ($e['organization_name']) $provider_entity["alumniOf"][] = ["@type" => "EducationalOrganization", "name" => $e['organization_name']]; } }
        $aff = get_field('affiliations', $post_id); if (is_array($aff)) { foreach ($aff as $a) { if ($a['name']) $provider_entity["affiliation"][] = ["@type" => "Hospital", "name" => $a['name']]; } }
        $sa = get_field('provider_profiles_sameas', $post_id); if (is_array($sa)) { foreach ($sa as $s) { if ($s['url']) $provider_entity["sameAs"][] = $s['url']; } }
        if (!empty($npi)) { if ($p_type === 'Physician') $provider_entity["usNPI"] = $npi; else $provider_entity["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }
        
        echo "\n<script type='application/ld+json'>" . json_encode(["@context" => "https://schema.org", "@graph" => [["@type" => "MedicalWebPage", "@id" => get_permalink() . "#webpage", "url" => get_permalink(), "name" => get_the_title()], $provider_entity]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- B. PROTECTED TREATMENT/CONDITION PAGES ---
    if ( is_singular( array( 'treatment', 'service' ) ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_aesthetic = in_array( 'aesthetics', $categories );
        $linked_providers = get_field('treatment_providers', $post_id);
        $treatment_schema = ["@context" => "https://schema.org", "@type" => "MedicalWebPage", "name" => get_the_title($post_id) . " at " . ($clinic_name ?: get_bloginfo('name')), "description" => get_field('procedure_description', $post_id) ?: get_the_excerpt(), "lastReviewed" => get_the_modified_date('c'), "mainEntity" => []];

        if ( $is_aesthetic ) {
            $procedure_entity = ["@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", "@id" => get_permalink($post_id) . "#procedure", "name" => get_field('clinical_name', $post_id) ?: get_the_title($post_id), "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id]];
            $p_alts = get_field('alternate_names', $post_id); if (!empty($p_alts)) $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $p_alts)));
            $p_img = get_field('primary_image', $post_id); if ($p_img) $procedure_entity["image"] = ["@type" => "ImageObject", "url" => is_array($p_img) ? $p_img['url'] : wp_get_attachment_url($p_img)];
            $treatment_schema["mainEntity"][] = $procedure_entity;

            $condition_repeater = get_field('medical_conditions_repeater', $post_id);
            if (is_array($condition_repeater)) { 
                foreach ($condition_repeater as $row) { 
                    $c_raw = isset($row['internal_treatment']) ? $row['internal_treatment'] : null;
                    $c_id = (is_object($c_raw) && isset($c_raw->ID)) ? $c_raw->ID : (is_array($c_raw) && isset($c_raw['ID']) ? $c_raw['ID'] : (is_numeric($c_raw) ? (int)$c_raw : 0));
                    if ($c_id > 0) { $treatment_schema["mainEntity"][] = $map_condition($c_id); } 
                } 
            }
        } else {
            $treatment_schema["mainEntity"][] = $map_condition($post_id);
        }

        if ( $linked_providers ) {
            $rev_id = $linked_providers[0]; $rev_type = get_field('provider_type', $rev_id) ?: 'Person'; $rev_npi = get_field('provider_npi', $rev_id);
            $treatment_schema["reviewedBy"] = ["@type" => $rev_type, "name" => get_the_title($rev_id)];
            if (!empty($rev_npi)) { if ($rev_type === 'Physician') $treatment_schema["reviewedBy"]["usNPI"] = $rev_npi; else $treatment_schema["reviewedBy"]["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $rev_npi]; }
            foreach ($linked_providers as $p_id) { $p_t = get_field('provider_type', $p_id) ?: 'Person'; $npi = get_field('provider_npi', $p_id); $p_o = ["@type" => $p_t, "name" => get_the_title($p_id), "url" => get_permalink($p_id)]; if (!empty($npi)) { if ($p_t === 'Physician') $p_o["usNPI"] = $npi; else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; } $treatment_schema["provider"][] = $p_o; }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- C. PROTECTED GLOBAL CLINIC ---
    if ( is_front_page() || ( is_page() && is_array($display_pages) && in_array(get_the_ID(), $display_pages) ) ) {
        $logo = get_field('logo', 'option');
        $clinic_schema = ["@context" => "https://schema.org", "@type" => "MedicalClinic", "@id" => $clinic_id, "name" => $clinic_name ?: get_bloginfo('name'), "logo" => ["@type" => "ImageObject", "url" => is_array($logo) ? $logo['url'] : (is_numeric($logo) ? wp_get_attachment_url($logo) : $logo)], "address" => ["@type" => "PostalAddress", "streetAddress" => $clinic_street, "addressLocality" => $clinic_city, "addressRegion" => $clinic_state, "postalCode" => $clinic_zip, "addressCountry" => "US"], "geo" => ["@type" => "GeoCoordinates", "latitude" => $clinic_lat, "longitude" => $clinic_lng], "telephone" => $clinic_phone, "url" => get_home_url(), "openingHoursSpecification" => []];
        $hours = get_field('hours_repeater', 'option'); if (is_array($hours)) { foreach ($hours as $h) { if (!empty($h['days'])) $clinic_schema["openingHoursSpecification"][] = ["@type" => "OpeningHoursSpecification", "dayOfWeek" => $h['days'], "opens" => $h['opens'], "closes" => $h['closes']]; } }
        echo "\n<script type='application/ld+json'>" . json_encode($clinic_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}