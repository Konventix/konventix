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
            export type ClaimLandingData = {
                readonly linkId: string;
                readonly eventName: string | null;
                readonly role: string;
                readonly managerName: string | null;
                readonly intendedEmail: string | null;
                readonly expiresAt: string;
                readonly alreadyHolderOfAnother: boolean;
                readonly claimUrl: string;
            };
            export type InviteData = {
                readonly email: string | null;
            };
            export type InviteStateData = {
                readonly intendedEmail: string | null;
                readonly expiresAt: string;
                readonly url: string;
            };
            export type ManagedAdmissionData = {
                readonly id: string;
                readonly role: string;
                readonly state: string;
                readonly holderUserId: string | null;
                readonly holderName: string | null;
                readonly isSelf: boolean;
                readonly checkedIn: boolean;
                readonly invite: Konventix.Admission.Data.InviteStateData | null;
                readonly seat: Konventix.Admission.Data.TicketSeatData | null;
            };
            export type MyTicketData = {
                readonly id: string;
                readonly eventId: string;
                readonly eventName: string;
                readonly role: string;
                readonly state: string;
                readonly holderName: string | null;
            };
            export type TicketManagerData = {
                readonly registrationId: string;
                readonly ticketCount: number;
                readonly holdsOne: boolean;
                readonly admissions: Konventix.Admission.Data.ManagedAdmissionData[];
            };
            export type TicketPageData = {
                readonly id: string;
                readonly eventId: string;
                readonly eventName: string | null;
                readonly eventSlug: string | null;
                readonly eventWhen: string | null;
                readonly venueLabel: string | null;
                readonly bannerUrl: string | null;
                readonly bannerAlt: string | null;
                readonly role: string;
                readonly state: string;
                readonly validRange: string;
                readonly holderName: string;
                readonly isHolder: boolean;
                readonly isManager: boolean;
                readonly checkedIn: boolean;
                readonly qrSvg: string;
                readonly doorCode: string;
                readonly seat: Konventix.Admission.Data.TicketSeatData | null;
                readonly registrationId: string | null;
            };
            export type TicketSeatData = {
                readonly bookingId: string;
                readonly placeRef: string | null;
                readonly placeLabel: string;
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
            export type AddToCartData = {
                readonly variantId: string;
                readonly quantity: number;
            };
            export type CartData = {
                readonly lines: Konventix.Commerce.Data.CartLineData[];
                readonly totalNet: string;
                readonly totalTax: string;
                readonly totalGross: string;
                readonly currency: string;
                readonly expiresAt: string | null;
                readonly accounts: Konventix.Commerce.Data.CheckoutAccountData[];
                readonly billingAddressRequired: boolean;
            };
            export type CartLineData = {
                readonly id: string;
                readonly kind: string;
                readonly label: string;
                readonly quantity: number;
                readonly unitAmountNet: string;
                readonly unitAmountGross: string;
                readonly lineGross: string;
                readonly canChangeQuantity: boolean;
                readonly maxQuantity: number | null;
            };
            export type CartLineQuantityData = {
                readonly quantity: number;
            };
            export type CheckoutAccountData = {
                readonly id: string;
                readonly provider: string;
                readonly label: string;
            };
            export type CommerceBrandSettingsData = {
                readonly reversalRepaymentDays: number;
            };
            export type CreditNoteMailData = {
                readonly creditNoteNumber: string;
                readonly invoiceNumber: string | null;
                readonly recipientName: string;
                readonly sellerName: string;
                readonly totalGross: string;
                readonly currency: string;
                readonly reasonLabelDe: string;
                readonly reasonLabelEn: string;
                readonly issuedOn: string;
            };
            export type MyCreditNoteData = {
                readonly number: string;
                readonly issuedOn: string;
                readonly totalGross: string;
                readonly currency: string;
                readonly documentUrl: string | null;
            };
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
                readonly invoicePaid: boolean;
                readonly payUrl: string | null;
                readonly documentUrl: string | null;
                readonly lines: Konventix.Commerce.Data.MyOrderLineData[];
                readonly creditNotes: Konventix.Commerce.Data.MyCreditNoteData[];
            };
            export type MyOrderLineData = {
                readonly label: string;
                readonly quantity: number;
                readonly lineGross: string;
                readonly kind: string | null;
                readonly registrationUrl: string | null;
            };
            export type OperationsNoticeData = {
                readonly kind: Konventix.Commerce.Enums.OperationsNoticeKind;
                readonly title: string;
                readonly detail: string;
                readonly url: string;
            };
            export type OrderConfirmationMailData = {
                readonly orderNumberShort: string;
                readonly recipientName: string | null;
                readonly placedAt: string;
                readonly lines: {
                    label: string;
                    quantity: number;
                    lineGross: string;
                }[];
                readonly totalGross: string;
                readonly currency: string;
                readonly paid: boolean;
                readonly reservationUntil: string | null;
                readonly ordersUrl: string;
                readonly payUrl: string | null;
            };
            export type OrderReversalData = {
                readonly id: string;
                readonly provider: string;
                readonly kind: Konventix.Commerce.Enums.ReversalKind;
                readonly status: Konventix.Commerce.Enums.ReversalStatus;
                readonly amount: string;
                readonly currency: string;
                readonly providerReference: string;
                readonly reasonCode: string | null;
                readonly feeAmount: string | null;
                readonly occurredAt: string;
                readonly finalizedAt: string | null;
                readonly repayDueOn: string | null;
            };
            export type PaymentFailedMailData = {
                readonly orderNumberShort: string;
                readonly reason: string;
                readonly retryUrl: string;
                readonly ordersUrl: string;
                readonly payBy: string | null;
            };
            export type PaymentReversedMailData = {
                readonly orderNumberShort: string;
                readonly amount: string;
                readonly currency: string;
                readonly reasonDe: string;
                readonly reasonEn: string;
                readonly dueOn: string;
                readonly payUrl: string | null;
                readonly ordersUrl: string;
            };
            export type ProductData = {
                readonly id: string;
                readonly brandId: string;
                readonly brandName: string;
                readonly eventId: string | null;
                readonly name: string;
                readonly slug: string;
                readonly description: string | null;
                readonly imageMediaId: string | null;
                readonly taxClass: Konventix.Commerce.Enums.TaxClass;
                readonly unitAmountNet: string;
                readonly priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
                readonly published: boolean;
                readonly variants: Konventix.Commerce.Data.ProductVariantData[];
            };
            export type ProductRowData = {
                readonly id: string;
                readonly name: string;
                readonly slug: string;
                readonly brandName: string;
                readonly unitAmountNet: string;
                readonly priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
                readonly published: boolean;
                readonly updatedAt: string | null;
            };
            export type ProductVariantData = {
                readonly id: string | null;
                readonly label: string | null;
                readonly sku: string | null;
                readonly unitAmountNet: string | null;
                readonly stockCapacity: number;
                readonly position: number;
                readonly remaining: number | null;
            };
            export type RegistrationCartSettlementData = {
                readonly status: string;
                readonly currency: string;
                readonly totalGross: string;
                readonly expiresAt: string | null;
                readonly cartUrl: string | null;
                readonly ordersUrl: string | null;
            };
            export type RegistrationSettlementData = {
                readonly orderId: string;
                readonly status: string;
                readonly currency: string;
                readonly totalGross: string;
                readonly payUrl: string | null;
                readonly repayDueOn: string | null;
                readonly invoiceNumber: string | null;
                readonly issuedOn: string | null;
                readonly dueOn: string | null;
                readonly documentUrl: string | null;
            };
            export type SaveProductData = {
                readonly name: string;
                readonly slug: string;
                readonly description: string | null;
                readonly eventId: string | null;
                readonly imageMediaId: string | null;
                readonly taxClass: Konventix.Commerce.Enums.TaxClass;
                readonly unitAmountNet: string;
                readonly priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
                readonly published: boolean;
                readonly stockCapacity: number | null;
                readonly variants: Konventix.Commerce.Data.ProductVariantData[];
            };
            export type SaveStripeSettingsData = {
                readonly secretKey: string;
                readonly webhookSecret: string;
                readonly publishableKey: string | null;
            };
            export type ShopProductData = {
                readonly id: string;
                readonly name: string;
                readonly slug: string;
                readonly description: string | null;
                readonly imageUrl: string | null;
                readonly unitAmountNet: string;
                readonly unitAmountGross: string;
                readonly priceDisplay: Konventix.Commerce.Enums.PriceDisplay;
                readonly soldOut: boolean;
                readonly variants: Konventix.Commerce.Data.ShopVariantData[];
            };
            export type ShopVariantData = {
                readonly id: string;
                readonly label: string | null;
                readonly unitAmountNet: string;
                readonly unitAmountGross: string;
                readonly remaining: number;
            };
            export type StripeSetupData = {
                readonly webhookUrl: string;
                readonly events: string[];
                readonly configured: boolean;
                readonly secretKeySet: boolean;
                readonly webhookSecretSet: boolean;
                readonly source: string;
                readonly mode: string | null;
                readonly lastFour: string | null;
                readonly publishableKeySet: boolean;
                readonly liveKeyOffProduction: boolean;
                readonly secretGroupHint: string;
            };
            export type UnappliedPaymentData = {
                readonly id: string;
                readonly provider: string;
                readonly amount: string;
                readonly currency: string;
                readonly externalId: string | null;
                readonly receivedAt: string | null;
            };
            export type UpdateSettlementAccountLabelData = {
                readonly checkoutLabel: string | null;
                readonly statementDescriptorSuffix: undefined | string | null;
            };
            export type WaitingRoomSettingsData = {
                readonly admitPerInterval: number;
                readonly admissionTtlMinutes: number;
            };
            export type WaitingRoomStatusData = {
                readonly position: number | null;
                readonly admitted: boolean;
                readonly redirectTo: string | null;
                readonly depth: number;
            };
        }
        namespace Enums {
            export type CartLineKind = 'product' | 'registration';
            export type CartStatus = 'open' | 'expired' | 'checked_out';
            export type HostedPaymentState =
                | 'paid'
                | 'not_paid'
                | 'in_flight'
                | 'unreachable'
                | 'unprobed'
                | 'unanswerable';
            export type OperationsNoticeKind =
                | 'stock-exhausted'
                | 'payment-failed'
                | 'payment-reconciled'
                | 'payment-reversed'
                | 'dispute-opened'
                | 'payment-unapplied'
                | 'sweep-cancelled-unsettled';
            export type OrderStatus =
                | 'pending'
                | 'paid'
                | 'cancelled'
                | 'refunded'
                | 'payment_reversed';
            export type PaymentStatus =
                'pending' | 'succeeded' | 'failed' | 'expired' | 'reversed';
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
                | 'commerce.export-bookkeeping'
                | 'commerce.view-products'
                | 'commerce.manage-products';
            export type PriceDisplay = 'net' | 'gross';
            export type RefundReason =
                | 'customer-request'
                | 'event-cancelled'
                | 'duplicate-payment'
                | 'goodwill';
            export type ReversalKind =
                'return' | 'dispute' | 'external_refund' | 'refund';
            export type ReversalStatus = 'open' | 'final' | 'withdrawn';
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
            export type CheckoutLegalTextGapData = {
                readonly brandId: string;
                readonly brandName: string;
                readonly missingSlugs: string[];
            };
            export type CheckoutLegalTextRoleData = {
                readonly slug: string;
                readonly name: string;
                readonly description: string;
                readonly requiresConsent: boolean;
            };
            export type PolicyData = {
                readonly name: string;
                readonly slug: string;
            };
            export type PolicyVersionData = {
                readonly version: string;
                readonly content: string;
                readonly requiresConsent: boolean;
                readonly lawfulBasis: string;
                readonly effectiveAt: string | null;
            };
        }
        namespace Enums {
            export type DataSubjectRequestState =
                'received' | 'in_progress' | 'fulfilled' | 'refused';
            export type DataSubjectRequestType =
                'export' | 'deletion' | 'rectification' | 'consent_withdrawal';
            export type DeletionMode = 'anonymize' | 'erase';
            export type LawfulBasis =
                'consent' | 'contract' | 'legitimate_interest';
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
                readonly bannerMediaId: string | null;
            };
            namespace Blocks {
                export type CountdownBlockData = {
                    readonly eventId: string;
                    readonly label: string | null;
                };
                export type CountdownResolvedData = {
                    readonly found: boolean;
                    readonly label: string | null;
                    readonly eventName: string | null;
                    readonly targetIso: string | null;
                };
                export type EventHeroBlockData = {
                    readonly eventId: string;
                };
                export type EventHeroResolvedData = {
                    readonly found: boolean;
                    readonly name: string | null;
                    readonly startsAt: string | null;
                    readonly endsAt: string | null;
                    readonly timezone: string | null;
                    readonly venueCity: string | null;
                };
            }
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
            export type AccountMergedClosedMailData = {
                readonly survivorEmail: string;
            };
            export type AccountMergedSurvivorMailData = {
                readonly otherEmail: string;
            };
            export type AccountPreferencesData = {
                readonly emailNotifications: boolean;
            };
            export type AdminUpdateUserData = {
                name: string;
                email: string;
                locale: string;
                timezone: string | null;
            };
            export type CompleteSocialSignupData = {
                readonly name: string;
                readonly username: string;
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
            export type MergeUserAccountData = {
                readonly email: string;
                readonly acknowledgedClosedAccountHistoryStaysBehind: boolean;
            };
            export type PasswordResetMailData = {
                readonly resetUrl: string;
                readonly validMinutes: number;
            };
            export type PasswordUpdateData = {
                readonly current_password: string;
                readonly password: string;
            };
            export type ProfileUpdateData = {
                readonly name: string;
                readonly email: string;
                readonly username: string;
                readonly tagline: string | null;
                readonly statusEmoji: string | null;
                readonly socialLinks: Record<string, string> | null;
                readonly visibility: string;
                readonly timezone: string | null;
            };
            export type RegisterData = {
                readonly name: string;
                readonly username: string;
                readonly email: string;
                readonly password: string;
            };
            export type RegistrationAttemptMailData = {
                readonly resetUrl: string;
            };
            export type SessionData = {
                readonly id: string;
                readonly ipAddress: string | null;
                readonly userAgent: string | null;
                readonly lastActiveAt: string;
                readonly isCurrentDevice: boolean;
            };
            export type VerifyEmailMailData = {
                readonly verifyUrl: string;
                readonly validMinutes: number;
            };
            export type WelcomeMailData = {
                readonly brandName: string;
                readonly homeUrl: string;
                readonly shopUrl: string | null;
            };
        }
        namespace Enums {
            export type AccountState =
                'active' | 'locked' | 'anonymized' | 'merged';
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
            export type ProfileVisibility = 'event' | 'internal' | 'public';
        }
    }
    namespace Messaging {
        namespace Data {
            export type BrandMailIdentityData = {
                readonly brandId: string;
                readonly brandName: string;
                readonly logoUrl: string | null;
                readonly logoAlt: string | null;
                readonly accentColor: string;
                readonly accentTextColor: string;
                readonly sellerLine: string;
                readonly imprintUrl: string | null;
                readonly privacyUrl: string | null;
            };
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
            export type MessageLogDetailData = {
                readonly id: string;
                readonly brandName: string;
                readonly recipientEmail: string;
                readonly recipientId: string | null;
                readonly templateKey: string;
                readonly channel: Konventix.Messaging.Enums.Channel;
                readonly status: Konventix.Messaging.Enums.MessageStatus;
                readonly queuedAt: string;
                readonly sentAt: string | null;
                readonly failedAt: string | null;
                readonly lastError: string | null;
                readonly resentFromId: string | null;
                readonly payload: Record<string, any> | null;
                readonly renderedSubject: string | null;
                readonly renderedBody: string | null;
                readonly bodyRedacted: boolean;
                readonly attachments: string[];
                readonly attempts: number;
                readonly topicKey: string | null;
                readonly urgent: boolean;
                readonly providerMessageId: string | null;
                readonly resends: Konventix.Messaging.Data.MessageResendRowData[];
            };
            export type MessageLogRowData = {
                readonly id: string;
                readonly brandName: string;
                readonly recipientEmail: string;
                readonly recipientId: string | null;
                readonly templateKey: string;
                readonly channel: Konventix.Messaging.Enums.Channel;
                readonly status: Konventix.Messaging.Enums.MessageStatus;
                readonly queuedAt: string;
                readonly sentAt: string | null;
                readonly failedAt: string | null;
                readonly lastError: string | null;
                readonly resentFromId: string | null;
            };
            export type MessageResendRowData = {
                readonly id: string;
                readonly queuedAt: string;
                readonly status: Konventix.Messaging.Enums.MessageStatus;
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
            export type SaveTemplateOverrideData = {
                readonly locale: string;
                readonly subject: string;
                readonly body: string;
            };
            export type TemplateEditData = {
                readonly key: string;
                readonly module: string;
                readonly locales: string[];
                readonly defaultLocale: string;
                readonly defaults: Record<
                    string,
                    {
                        subject: string;
                        body: string;
                    }
                >;
                readonly overrides: Record<
                    string,
                    {
                        subject: string;
                        body: string;
                    } | null
                >;
                readonly sample: Record<string, any>;
                readonly brandId: string;
                readonly brandName: string;
            };
            export type TemplatePreviewData = {
                readonly subject: string;
                readonly body: string;
                readonly locale: string;
            };
            export type TemplateSummaryData = {
                readonly key: string;
                readonly module: string;
                readonly locales: string[];
                readonly defaultLocale: string;
                readonly overriddenLocales: string[];
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
            export type BrandFeatureData = {
                name: string;
                label: string;
                description: string;
                active: boolean;
                settingsComponent: string | null;
                settingsProps: Record<string, any> | null;
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
            export type CookieDeclarationData = {
                name: string;
                purpose: string;
                category: Konventix.Platform.Enums.CookieCategory;
                lifetime: string;
                module: string;
            };
            export type CookiePreferencesData = {
                readonly functional: boolean;
            };
            export type CreateStaticPageData = {
                readonly title: string;
                readonly slug: string;
                readonly brandId: string | null;
            };
            export type EnvironmentRibbonData = {
                name: string;
                label: string;
                color: string;
                release: string;
            };
            export type FooterColumnData = {
                heading: string | null;
                blocks: Konventix.Platform.Data.Blocks.BlockData[];
            };
            export type FooterColumnsData = {
                brandId: string | null;
                columns: Konventix.Platform.Data.FooterColumnData[];
            };
            export type FooterLinkData = {
                label: string;
                kind: Konventix.Platform.Enums.FooterLinkKind;
                url: string | null;
                dialogKey: string | null;
                opensInNewTab: boolean;
            };
            export type FooterLinksData = {
                brandId: string | null;
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
            export type OperatorBrandLinkData = {
                readonly id: string;
                readonly name: string;
                readonly url: string | null;
                readonly current: boolean;
            };
            export type OperatorPresenceData = {
                readonly name: string;
                readonly legalName: string;
                readonly brandName: string;
                readonly brands: Konventix.Platform.Data.OperatorBrandLinkData[];
                readonly privacyPolicyUrl: string | null;
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
                readonly signupsOpen: boolean;
                readonly dateFormat: Konventix.Platform.Enums.DateFormat;
                readonly timeFormat: Konventix.Platform.Enums.TimeFormat;
            };
            export type StaticPageData = {
                readonly id: string;
                readonly brandId: string;
                readonly brandName: string;
                readonly title: string;
                readonly slug: string;
                readonly published: boolean;
                readonly publicUrl: string | null;
                readonly blocks: Konventix.Platform.Data.Blocks.BlockData[];
            };
            export type StaticPageRowData = {
                readonly id: string;
                readonly title: string;
                readonly slug: string;
                readonly brandName: string;
                readonly published: boolean;
                readonly updatedAt: string | null;
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
            export type UpdateStaticPageData = {
                readonly title: string;
                readonly slug: string;
                readonly published: boolean;
                readonly blocks: Konventix.Platform.Data.Blocks.BlockData[];
            };
            namespace Blocks {
                export type BlockData = {
                    readonly type: string;
                    readonly data: Record<string, any>;
                };
                export type BlockTypeOptionData = {
                    readonly key: string;
                    readonly label: string;
                    readonly module: string;
                    readonly editorComponent: string;
                    readonly defaultData: Record<string, any>;
                };
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
                export type SocialLinkData = {
                    readonly network: string;
                    readonly url: string;
                    readonly label: string | null;
                };
                export type SocialLinksBlockData = {
                    readonly links: Konventix.Platform.Data.Blocks.SocialLinkData[];
                };
                export type TextBlockData = {
                    heading: string | null;
                    body: string;
                };
            }
        }
        namespace Enums {
            export type CookieCategory = 'necessary' | 'functional';
            export type DateFormat = 'DD.MM.YYYY' | 'MM/DD/YYYY' | 'YYYY-MM-DD';
            export type FooterLinkKind = 'url' | 'dialog';
            export type Permission =
                | 'platform.access-admin'
                | 'platform.view-brands'
                | 'platform.manage-brands'
                | 'platform.manage-brand-domains'
                | 'platform.manage-brand-theme'
                | 'platform.manage-module-activation'
                | 'platform.view-static-pages'
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
            export type TimeFormat = '24h' | '12h';
        }
    }
    namespace Registration {
        namespace Data {
            export type GrantData = {
                readonly kind: Konventix.Registration.Enums.GrantKind;
                readonly role: string;
                readonly quantity: number;
                readonly resourceTypeId: string | null;
                readonly resourceTypeIds: string[] | null;
                readonly seatingMode: Konventix.Registration.Enums.SeatingMode | null;
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
                readonly onSale: boolean;
                readonly salesStart: string | null;
                readonly eventName: string | null;
            };
            export type QuotaData = {
                readonly name: string;
                readonly capacity: number;
                readonly resourceTypeId: string | null;
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
            export type RegistrationMailData = {
                readonly registrationTypeName: string;
                readonly registrationUrl: string;
                readonly eventName: string | null;
                readonly holderName: string | null;
                readonly nextStep: string | null;
                readonly reason: string | null;
                readonly cancelReason: string | null;
                readonly wasPaid: boolean;
                readonly cartUrl: string | null;
                readonly cartHoldExpiresAt: string | null;
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
                readonly salesStart: string | null;
                readonly salesEnd: string | null;
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
            export type GrantKind = 'admission' | 'resource_claim' | 'seating';
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
            export type SeatingMode = 'pick' | 'assign';
            export type TaxClass = 'standard' | 'reduced';
            export type WaitlistState =
                'queued' | 'offered' | 'converted' | 'expired' | 'withdrawn';
        }
    }
    namespace Resources {
        namespace Data {
            export type AdmissionSeatRowData = {
                readonly admissionId: string;
                readonly number: number;
                readonly placeRef: string | null;
                readonly isHeld: boolean;
            };
            export type BackgroundData = {
                readonly mediaId: string;
                readonly scaleMmPerPx: number;
                readonly offsetXMm: number;
                readonly offsetYMm: number;
            };
            export type CategoryData = {
                readonly id: string;
                readonly name: string;
                readonly token: Konventix.Resources.Enums.CategoryToken;
                readonly symbol: string | null;
                readonly description: string | null;
                readonly mode: Konventix.Resources.Enums.CapacityMode;
            };
            export type LabelSchemaData = {
                readonly row: string;
                readonly place: string;
            };
            export type LayoutData = {
                readonly type: Konventix.Resources.Enums.LayoutKind;
                readonly spacing: number;
                readonly count: number | null;
                readonly rows: number | null;
                readonly cols: number | null;
                readonly rowSpacing: number | null;
                readonly startIndex: number;
            };
            export type PickPlacesData = {
                readonly placeIds: string[];
            };
            export type PlanAvailabilityData = {
                readonly eventId: string;
                readonly generatedAt: string;
                readonly places: Record<
                    string,
                    Konventix.Resources.Enums.PlaceState
                >;
            };
            export type PlanConflictBookingData = {
                readonly bookingId: string;
                readonly holderType: string;
                readonly registrationId: string | null;
                readonly claimId: string | null;
            };
            export type PlanConflictData = {
                readonly placeId: string;
                readonly ref: string | null;
                readonly label: string;
                readonly reason: Konventix.Resources.Enums.ConflictReason;
                readonly bookings: Konventix.Resources.Data.PlanConflictBookingData[];
            };
            export type PlanConflictResolutionData = {
                readonly placeId: string;
                readonly resolution: string;
            };
            export type PlanData = {
                readonly eventId: string;
                readonly name: string;
                readonly floorLabel: string | null;
                readonly widthMm: number;
                readonly heightMm: number;
                readonly unitDisplay: string;
                readonly snapStep: number;
            };
            export type PlanDiffSummaryData = {
                readonly added: number;
                readonly changed: number;
                readonly removed: number;
            };
            export type PlanDocumentData = {
                readonly schema: string;
                readonly elements: Konventix.Resources.Data.PlanElementData[];
            };
            export type PlanEditorData = {
                readonly plan: Konventix.Resources.Data.PlanViewData;
                readonly eventId: string;
                readonly version: number;
                readonly snapStep: number;
                readonly unitDisplay: string;
                readonly publishedAt: string | null;
                readonly canReserve: boolean;
                readonly categories: Konventix.Resources.Data.CategoryData[];
                readonly occupancy: Konventix.Resources.Data.PlanOccupancyData;
            };
            export type PlanElementData = {
                readonly id: string;
                readonly kind: Konventix.Resources.Enums.ElementKind;
                readonly x: number;
                readonly y: number;
                readonly shape: Konventix.Resources.Data.ShapeData;
                readonly parentId: string | null;
                readonly ref: string | null;
                readonly label: string | null;
                readonly layer: string;
                readonly rotation: number;
                readonly locked: boolean;
                readonly originId: string | null;
                readonly token: Konventix.Resources.Enums.CategoryToken | null;
                readonly layout: Konventix.Resources.Data.LayoutData | null;
                readonly labelSchema: Konventix.Resources.Data.LabelSchemaData | null;
                readonly resourceTypeId: string | null;
                readonly accessible: boolean;
                readonly detached: boolean;
                readonly blocked: boolean;
                readonly fixtureType: Konventix.Resources.Enums.FixtureType | null;
                readonly text: string | null;
                readonly fontSizeMm: number | null;
            };
            export type PlanOccupancyData = {
                readonly places: Record<
                    string,
                    Konventix.Resources.Data.PlanOccupancyPlaceData
                >;
            };
            export type PlanOccupancyPlaceData = {
                readonly state: Konventix.Resources.Enums.PlaceState;
                readonly holderType: string | null;
                readonly registrationId: string | null;
                readonly bookingId: string | null;
            };
            export type PlanRowData = {
                readonly id: string;
                readonly name: string;
                readonly eventName: string;
                readonly floorLabel: string | null;
                readonly places: number;
                readonly version: number;
                readonly publishedAt: string | null;
            };
            export type PlanSaveData = {
                readonly expectedVersion: number;
                readonly document: Konventix.Resources.Data.PlanDocumentData;
                readonly resolutions: Konventix.Resources.Data.PlanConflictResolutionData[];
            };
            export type PlanSaveResultData = {
                readonly version: number;
                readonly changedPlaceIds: string[];
                readonly warnings: Konventix.Resources.Data.PlanWarningData[];
                readonly savedAt: string;
            };
            export type PlanValidationData = {
                readonly conflicts: Konventix.Resources.Data.PlanConflictData[];
                readonly warnings: Konventix.Resources.Data.PlanWarningData[];
                readonly diff: Konventix.Resources.Data.PlanDiffSummaryData;
            };
            export type PlanViewData = {
                readonly id: string;
                readonly name: string;
                readonly floorLabel: string | null;
                readonly widthMm: number;
                readonly heightMm: number;
                readonly background: Konventix.Resources.Data.BackgroundData | null;
                readonly document: Konventix.Resources.Data.PlanDocumentData;
            };
            export type PlanWarningData = {
                readonly code: Konventix.Resources.Enums.PlanWarningCode;
                readonly placeIds: string[];
                readonly message: string;
            };
            export type ResourceTypeData = {
                readonly eventId: string;
                readonly name: string;
                readonly slug: string;
                readonly mode: Konventix.Resources.Enums.CapacityMode;
                readonly holdMinutes: number;
                readonly description: string | null;
                readonly token: Konventix.Resources.Enums.CategoryToken;
                readonly symbol: string | null;
                readonly sortOrder: number;
                readonly defaultShape: Konventix.Resources.Data.ShapeData | null;
            };
            export type ResourceTypeRowData = {
                readonly id: string;
                readonly name: string;
                readonly slug: string;
                readonly eventName: string;
                readonly mode: Konventix.Resources.Enums.CapacityMode;
                readonly token: Konventix.Resources.Enums.CategoryToken;
                readonly symbol: string | null;
                readonly sortOrder: number;
                readonly places: number;
            };
            export type SeatClaimData = {
                readonly id: string;
                readonly resourceTypeIds: string[];
                readonly seatingMode: Konventix.Resources.Enums.SeatingMode;
                readonly quantity: number;
                readonly state: Konventix.Resources.Enums.ClaimState;
            };
            export type SeatPickerData = {
                readonly registrationId: string;
                readonly claim: Konventix.Resources.Data.SeatClaimData;
                readonly categories: Konventix.Resources.Data.CategoryData[];
                readonly plans: Konventix.Resources.Data.PlanViewData[];
                readonly availability: Konventix.Resources.Data.PlanAvailabilityData;
                readonly currentPlaceIds: string[];
                readonly admissionId: string | null;
                readonly maxPlaces: number | null;
            };
            export type SeatSummaryData = {
                readonly registrationId: string;
                readonly claimState: string;
                readonly quantity: number;
                readonly placeRefs: string[];
                readonly noFreePlaces: boolean;
                readonly seatingMode: Konventix.Resources.Enums.SeatingMode;
                readonly admissions: Konventix.Resources.Data.AdmissionSeatRowData[];
            };
            export type ShapeData = {
                readonly type: Konventix.Resources.Enums.ShapeKind;
                readonly w: number | null;
                readonly h: number | null;
                readonly radius: number | null;
                readonly rx: number | null;
                readonly ry: number | null;
                readonly points: number[][] | null;
            };
            export type TicketSeatActionData = {
                readonly admissionId: string;
                readonly placeLabel: string | null;
                readonly seatingMode: string;
                readonly noFreePlaces: boolean;
            };
        }
        namespace Enums {
            export type BookingState =
                'held' | 'confirmed' | 'released' | 'cancelled' | 'expired';
            export type CapacityMode = 'unit' | 'quantity';
            export type CategoryToken =
                | 'chart-1'
                | 'chart-2'
                | 'chart-3'
                | 'chart-4'
                | 'chart-5'
                | 'accent'
                | 'success'
                | 'warning'
                | 'danger';
            export type ClaimState = 'unplaced' | 'placed' | 'released';
            export type ConflictReason =
                'deleted' | 'category_changed' | 'blocked';
            export type ElementKind =
                'zone' | 'group' | 'place' | 'fixture' | 'annotation';
            export type FixtureType =
                'table' | 'counter' | 'stage' | 'wall' | 'pillar' | 'custom';
            export type LayoutKind = 'linear' | 'grid';
            export type Permission =
                | 'resources.view-resources'
                | 'resources.manage-resource-types'
                | 'resources.manage-resources'
                | 'resources.manage-plans'
                | 'resources.book-on-behalf'
                | 'resources.release-bookings';
            export type PlaceState =
                'free' | 'mine' | 'held' | 'booked' | 'unavailable';
            export type PlanVersionSource = 'editor' | 'import' | 'copy';
            export type PlanWarningCode = 'overlap' | 'outside_zone';
            export type SeatingMode = 'pick' | 'assign';
            export type ShapeKind = 'point' | 'rect' | 'ellipse' | 'polygon';
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
