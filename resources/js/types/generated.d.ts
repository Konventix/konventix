declare namespace Konventix {
    namespace Admission {
        namespace Data {
            export type BadgeLayoutData = {
                readonly size: string;
                readonly orientation: string;
                readonly logoMediaId: string | null;
                readonly backgroundMediaId: string | null;
                readonly accentColor: string;
                readonly showRole: boolean;
                readonly showQrCode: boolean;
                readonly footerText: string | null;
            };
        }
        namespace Enums {
            export type AdmissionState =
                'issued' | 'assigned' | 'revoked' | 'expired';
            export type CheckInRejectionReason =
                | 'unknown_admission'
                | 'invalid_signature'
                | 'token_expired'
                | 'nonce_superseded'
                | 'admission_not_valid'
                | 'outside_event_day'
                | 'not_valid_on_this_day'
                | 'reentry_not_permitted'
                | 'wrong_event';
            export type CheckInResult = 'accepted' | 'rejected';
            export type Permission =
                | 'admission.view-admissions'
                | 'admission.issue-admissions'
                | 'admission.revoke-admissions'
                | 'admission.assign-holder'
                | 'admission.reassign-holder'
                | 'admission.manage-checkpoints'
                | 'admission.view-check-in-log'
                | 'admission.scan-check-in'
                | 'admission.print-badge'
                | 'admission.manage-badge-templates';
            export type ReentryPolicy =
                'once_only' | 'once_per_day' | 'unlimited';
        }
    }
    namespace Commerce {
        namespace Data {
            export type MyOrderData = {
                readonly id: string;
                readonly placedAt: string;
                readonly settlesType: string;
                readonly settlesId: string;
                readonly settlesLabel: string;
                readonly status: Konventix.Commerce.Enums.OrderStatus;
                readonly currency: string;
                readonly totalGross: string;
                readonly invoiceNumber: string | null;
                readonly payUrl: string | null;
                readonly documentUrl: string | null;
            };
            export type RegistrationSettlementData = {
                readonly orderId: string;
                readonly status: string;
                readonly currency: string;
                readonly totalGross: string;
                readonly payUrl: string | null;
                readonly invoiceNumber: string | null;
                readonly issuedOn: string | null;
                readonly dueOn: string | null;
                readonly documentUrl: string | null;
            };
        }
        namespace Enums {
            export type OrderStatus =
                'pending' | 'paid' | 'cancelled' | 'refunded';
            export type PaymentStatus =
                'pending' | 'succeeded' | 'failed' | 'expired';
            export type Permission =
                | 'commerce.view-orders'
                | 'commerce.view-invoices'
                | 'commerce.issue-invoices'
                | 'commerce.cancel-invoices'
                | 'commerce.book-manual-payment'
                | 'commerce.issue-refunds'
                | 'commerce.issue-credit-notes'
                | 'commerce.run-dunning'
                | 'commerce.manage-vouchers'
                | 'commerce.manage-settlement-account'
                | 'commerce.export-bookkeeping';
            export type TaxClass = 'standard' | 'reduced';
            export type TaxTreatment =
                'standard' | 'reverse_charge' | 'small_business';
        }
    }
    namespace Competition {
        namespace Enums {
            export type DisciplineKind =
                'video_game' | 'tabletop' | 'quiz' | 'judged';
            export type EntrantState =
                'registered' | 'checked_in' | 'withdrawn' | 'disqualified';
            export type MatchState =
                'pending' | 'scheduled' | 'live' | 'finished' | 'cancelled';
            export type Permission =
                | 'competition.view-competitions'
                | 'competition.manage-disciplines'
                | 'competition.manage-competitions'
                | 'competition.manage-entrants'
                | 'competition.record-results'
                | 'competition.correct-results';
        }
    }
    namespace Compliance {
        namespace Data {
            export type PolicyData = {
                readonly name: string;
                readonly slug: string;
            };
            export type PolicyVersionData = {
                readonly version: string;
                readonly content: string;
                readonly requiresConsent: boolean;
                readonly effectiveAt: string | null;
            };
        }
        namespace Enums {
            export type DataSubjectRequestState =
                'received' | 'in_progress' | 'fulfilled' | 'refused';
            export type DataSubjectRequestType =
                'export' | 'deletion' | 'rectification';
            export type DeletionMode = 'anonymize' | 'erase';
            export type ModuleResponseState =
                'pending' | 'completed' | 'failed';
            export type Permission =
                | 'compliance.view-policies'
                | 'compliance.manage-policies'
                | 'compliance.publish-policy'
                | 'compliance.view-consents'
                | 'compliance.view-subject-requests'
                | 'compliance.handle-subject-requests'
                | 'compliance.download-subject-export'
                | 'compliance.view-audit-log'
                | 'compliance.manage-retention-rules';
            export type PolicyVersionState =
                'draft' | 'published' | 'superseded';
            export type RetentionClockStart = 'record_age' | 'event_concluded';
        }
    }
    namespace Content {
        namespace Data {
            export type ContentEntryData = {
                readonly type: Konventix.Content.Enums.ContentType;
                readonly title: string;
                readonly slug: string;
                readonly audience: Konventix.Content.Enums.Audience;
                readonly commentsEnabled: boolean;
                readonly teaser: string | null;
                readonly body: string | null;
                readonly urgency: Konventix.Content.Enums.Urgency | null;
                readonly eventId: string | null;
            };
            export type LatestNewsBlockData = {
                readonly heading: string | null;
                readonly limit: number;
            };
            export type PostCommentData = {
                readonly body: string;
            };
            export type PublicCommentData = {
                readonly id: string;
                readonly body: string;
                readonly authorName: string;
                readonly postedAt: string;
                readonly isMine: boolean;
                readonly reactionCount: number;
                readonly hasReacted: boolean;
            };
            export type PublicEntryData = {
                readonly slug: string;
                readonly type: Konventix.Content.Enums.ContentType;
                readonly title: string;
                readonly teaser: string | null;
                readonly body: string | null;
                readonly urgency: Konventix.Content.Enums.Urgency | null;
                readonly authorName: string | null;
                readonly publishedAt: string | null;
                readonly commentsEnabled: boolean;
            };
        }
        namespace Enums {
            export type Audience = 'everyone' | 'logged-in';
            export type CommentState =
                'pending' | 'approved' | 'rejected' | 'hidden';
            export type ContentType = 'news' | 'page' | 'announcement';
            export type Permission =
                | 'content.view-entries'
                | 'content.manage-entries'
                | 'content.publish-entries'
                | 'content.moderate-comments';
            export type PublicationState =
                | 'draft'
                | 'scheduled'
                | 'published'
                | 'unpublished'
                | 'archived';
            export type Urgency = 'normal' | 'urgent';
        }
    }
    namespace Event {
        namespace Data {
            export type EventData = {
                readonly name: string;
                readonly slug: string;
                readonly description: string | null;
                readonly startsAt: string;
                readonly endsAt: string;
                readonly timezone: string;
                readonly visibility: Konventix.Event.Enums.EventVisibility;
                readonly reentryPolicy: Konventix.Event.Enums.ReentryPolicy;
                readonly predecessorEventId: string | null;
            };
        }
        namespace Enums {
            export type EventLifecycleState =
                | 'draft'
                | 'announced'
                | 'published'
                | 'running'
                | 'concluded'
                | 'archived'
                | 'cancelled';
            export type EventVisibility = 'public' | 'unlisted';
            export type Permission =
                | 'event.view-events'
                | 'event.create-event'
                | 'event.update-event'
                | 'event.publish-event'
                | 'event.reschedule-event'
                | 'event.cancel-event'
                | 'event.archive-event'
                | 'event.view-venues'
                | 'event.manage-venues';
            export type ReentryPolicy =
                'once_only' | 'once_per_day' | 'unlimited';
        }
    }
    namespace Identity {
        namespace Data {
            export type AccountPreferencesData = {
                readonly emailNotifications: boolean;
            };
            export type AdminUpdateUserData = {
                name: string;
                email: string;
                locale: string;
                timezone: string;
            };
            export type CompleteSocialSignupData = {
                readonly name: string;
                readonly email: string;
            };
            export type LinkedAccountData = {
                readonly provider: string;
                readonly label: string;
                readonly linked: boolean;
                readonly nickname: string | null;
                readonly avatarUrl: string | null;
                readonly linkedAt: string | null;
                readonly canUnlink: boolean;
            };
            export type PasswordUpdateData = {
                readonly current_password: string;
                readonly password: string;
            };
            export type ProfileUpdateData = {
                readonly name: string;
                readonly email: string;
            };
            export type RegisterData = {
                readonly name: string;
                readonly email: string;
                readonly password: string;
            };
            export type SessionData = {
                readonly id: string;
                readonly ipAddress: string | null;
                readonly userAgent: string | null;
                readonly lastActiveAt: string;
                readonly isCurrentDevice: boolean;
            };
        }
        namespace Enums {
            export type AccountState = 'active' | 'locked' | 'anonymized';
            export type Permission =
                | 'identity.view-users'
                | 'identity.manage-users'
                | 'identity.lock-user'
                | 'identity.unlock-user'
                | 'identity.reset-user-two-factor'
                | 'identity.view-user-sessions'
                | 'identity.revoke-user-sessions'
                | 'identity.manage-social-providers'
                | 'identity.impersonate-user';
        }
    }
    namespace Messaging {
        namespace Data {
            export type MailSettingsData = {
                fromName: string;
                fromAddress: string;
                replyTo: string | null;
                timezone: string | null;
                useCustomTransport: boolean;
                smtpHost: string | null;
                smtpPort: number | null;
                smtpUsername: string | null;
                smtpPassword: string | null;
                smtpScheme: string | null;
            };
            export type NotificationData = {
                readonly id: string;
                readonly topicKey: string | null;
                readonly title: string;
                readonly body: string;
                readonly url: string | null;
                readonly readAt: string | null;
                readonly createdAt: string;
            };
        }
        namespace Enums {
            export type Channel = 'mail' | 'in_app' | 'push';
            export type MessageStatus =
                'queued' | 'held' | 'sent' | 'failed' | 'suppressed';
            export type Permission =
                | 'messaging.view-message-log'
                | 'messaging.view-message-content'
                | 'messaging.resend-message'
                | 'messaging.manage-message-templates'
                | 'messaging.manage-sender-identity'
                | 'messaging.view-suppression-list'
                | 'messaging.manage-suppression-list';
        }
    }
    namespace Platform {
        namespace Data {
            export type AdminFavoriteData = {
                label: string;
                url: string;
            };
            export type AdminFavoriteOrderData = {
                ids: string[];
            };
            export type BrandData = {
                name: string;
                slug: string;
                hosts: string[];
            };
            export type BrandIdentityData = {
                name: string;
                logoUrl: string | null;
                logoDarkUrl: string | null;
                logoAlt: string | null;
                faviconUrl: string | null;
            };
            export type BrandStorageSettingsData = {
                useOwnStorage: boolean;
                bucket: string | null;
                region: string | null;
                endpoint: string | null;
                prefix: string | null;
                usePathStyleEndpoint: boolean;
                publicDirect: boolean;
                url: string | null;
                accessKeyId: string | null;
                secretAccessKey: string | null;
            };
            export type BrandSummaryData = {
                id: string;
                name: string;
                slug: string;
            };
            export type BrandThemeData = {
                tokens: Record<string, string>;
                darkTokens: Record<string, string>;
            };
            export type ConsoleSelectionData = {
                readonly brandId: string | null;
                readonly eventId: string | null;
            };
            export type FooterLinkData = {
                label: string;
                kind: Konventix.Platform.Enums.FooterLinkKind;
                url: string | null;
                dialogKey: string | null;
                opensInNewTab: boolean;
            };
            export type FooterLinksData = {
                links: Konventix.Platform.Data.FooterLinkData[];
            };
            export type MediaItemData = {
                id: string;
                brandId: string;
                originalFilename: string;
                mimeType: string;
                sizeBytes: number;
                altText: string | null;
                url: string | null;
                thumbUrl: string | null;
                usageCount: number;
                variantsPending: boolean;
                uploadedAt: string | null;
            };
            export type OperatorSettingsData = {
                readonly name: string;
                readonly legalName: string;
                readonly contactEmail: string | null;
                readonly addressLine: string | null;
                readonly postalCode: string | null;
                readonly city: string | null;
                readonly countryCode: string;
                readonly vatId: string | null;
                readonly taxNumber: string | null;
                readonly smallBusinessScheme: boolean;
            };
            export type ThemeCatalogData = {
                groups: Konventix.Platform.Data.ThemeTokenGroupData[];
                contrastPairs: Konventix.Platform.Data.ThemeContrastPairData[];
            };
            export type ThemeContrastPairData = {
                text: string;
                surface: string;
            };
            export type ThemeTokenDefinitionData = {
                name: string;
                label: string;
                light: string;
                dark: string;
            };
            export type ThemeTokenGroupData = {
                key: string;
                label: string;
                tokens: Konventix.Platform.Data.ThemeTokenDefinitionData[];
            };
            export type UpdateBrandData = {
                name: string;
                slug: string;
                currency: string;
                hosts: string[];
                logoMediaId: string | null;
                logoDarkMediaId: string | null;
                faviconMediaId: string | null;
            };
            namespace Blocks {
                export type CardItemData = {
                    title: string;
                    body: string;
                    icon: string | null;
                };
                export type CardsBlockData = {
                    cards: Konventix.Platform.Data.Blocks.CardItemData[];
                };
                export type HeroBlockData = {
                    heading: string;
                    subheading: string | null;
                    ctaLabel: string | null;
                    ctaUrl: string | null;
                    imageMediaId: string | null;
                };
                export type HeroResolvedData = {
                    readonly heading: string;
                    readonly subheading: string | null;
                    readonly ctaLabel: string | null;
                    readonly ctaUrl: string | null;
                    readonly imageUrl: string | null;
                    readonly imageAlt: string | null;
                };
                export type TextBlockData = {
                    heading: string | null;
                    body: string;
                };
            }
        }
        namespace Enums {
            export type FooterLinkKind = 'url' | 'dialog';
            export type Permission =
                | 'platform.access-admin'
                | 'platform.view-brands'
                | 'platform.manage-brands'
                | 'platform.manage-brand-domains'
                | 'platform.manage-brand-theme'
                | 'platform.manage-module-activation'
                | 'platform.manage-static-pages'
                | 'platform.manage-roles'
                | 'platform.view-cross-brand'
                | 'platform.view-media'
                | 'platform.manage-media'
                | 'platform.manage-storage-settings'
                | 'platform.manage-operator'
                | 'platform.manage-footer-links'
                | 'platform.view-diagnostics'
                | 'platform.view-request-log';
        }
    }
    namespace Registration {
        namespace Data {
            export type GrantData = {
                readonly kind: Konventix.Registration.Enums.GrantKind;
                readonly role: string;
                readonly quantity: number;
                readonly resourceTypeId: string | null;
            };
            export type MyRegistrationData = {
                readonly id: string;
                readonly eventId: string;
                readonly eventName: string;
                readonly typeName: string;
                readonly status: string;
                readonly paymentStatus: string;
                readonly organizationName: string | null;
                readonly submittedAt: string;
                readonly confirmedAt: string | null;
            };
            export type PriceDeclarationData = {
                readonly unitAmountNet: string;
                readonly taxClass: Konventix.Registration.Enums.TaxClass;
                readonly display: Konventix.Registration.Enums.PriceDisplay;
            };
            export type PublicOfferData = {
                readonly registrationTypeId: string;
                readonly name: string;
                readonly url: string;
                readonly price: Konventix.Registration.Data.PriceDeclarationData | null;
                readonly requiresApproval: boolean;
            };
            export type QuotaData = {
                readonly name: string;
                readonly capacity: number;
            };
            export type RegistrationFormData = {
                readonly registrationTypeId: string;
                readonly eventId: string;
                readonly name: string;
                readonly settlement: string;
                readonly confirmationGate: string;
                readonly price: Konventix.Registration.Data.PriceDeclarationData | null;
                readonly termsDays: number;
            };
            export type RegistrationTypeData = {
                readonly eventId: string;
                readonly name: string;
                readonly visibility: Konventix.Registration.Enums.RegistrationVisibility;
                readonly approval: Konventix.Registration.Enums.RegistrationApproval;
                readonly settlement: Konventix.Registration.Enums.RegistrationSettlement;
                readonly confirmationGate: Konventix.Registration.Enums.ConfirmationGate;
                readonly termsDays: number;
                readonly grants: Konventix.Registration.Data.GrantData[];
                readonly priceDeclaration: Konventix.Registration.Data.PriceDeclarationData | null;
                readonly quotaIds: string[];
            };
            export type SubmitRegistrationData = {
                readonly organizationName: string | null;
                readonly billingRecipientName: string | null;
                readonly billingEmail: string | null;
                readonly billingAddressLine: string | null;
                readonly billingPostalCode: string | null;
                readonly billingCity: string | null;
                readonly billingCountryCode: string | null;
                readonly billingVatId: string | null;
            };
        }
        namespace Enums {
            export type ConfirmationGate =
                'on_submit' | 'on_approval' | 'on_payment';
            export type GrantKind = 'admission' | 'resource_claim';
            export type PaymentStatus = 'none' | 'pending' | 'paid' | 'overdue';
            export type Permission =
                | 'registration.view-registrations'
                | 'registration.manage-registration-types'
                | 'registration.manage-quotas'
                | 'registration.approve-registrations'
                | 'registration.reject-registrations'
                | 'registration.cancel-registrations'
                | 'registration.issue-invitation-tokens'
                | 'registration.export-registrations';
            export type PriceDisplay = 'net' | 'gross';
            export type RegistrationApproval = 'none' | 'required';
            export type RegistrationSettlement =
                'free' | 'instant' | 'invoice' | 'external';
            export type RegistrationStatus =
                | 'submitted'
                | 'approved'
                | 'rejected'
                | 'confirmed'
                | 'cancelled';
            export type RegistrationVisibility =
                'public' | 'link' | 'invite_only';
            export type TaxClass = 'standard' | 'reduced';
            export type WaitlistState =
                'queued' | 'offered' | 'converted' | 'expired' | 'withdrawn';
        }
    }
    namespace Resources {
        namespace Enums {
            export type BookingState =
                'held' | 'confirmed' | 'released' | 'cancelled' | 'expired';
            export type CapacityMode = 'unit' | 'quantity';
            export type Permission =
                | 'resources.view-resources'
                | 'resources.manage-resource-types'
                | 'resources.manage-resources'
                | 'resources.book-on-behalf'
                | 'resources.release-bookings';
        }
    }
    namespace Staffing {
        namespace Enums {
            export type AssignmentState =
                | 'offered'
                | 'accepted'
                | 'declined'
                | 'released'
                | 'completed'
                | 'no_show';
            export type Permission =
                | 'staffing.view-roster'
                | 'staffing.manage-teams'
                | 'staffing.manage-shift-roles'
                | 'staffing.manage-shifts'
                | 'staffing.manage-assignments'
                | 'staffing.claim-own-shifts';
        }
    }
}
